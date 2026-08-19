<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

use RuntimeException;

/**
 * Zstandard through the `zstd` binary, for hosts without `ext-zstd`.
 *
 * This exists because the extension is absent on many hosts and gzip costs 76% more stored bytes.
 * It is explicitly NOT a per-frame codec: a process spawn dominates the work at frame sizes, and
 * 1,000 separate `zstd` invocations were measured at 5.88 seconds - roughly 5.9 ms each against
 * the sub-millisecond compression itself.
 *
 * The contract is therefore split. ZstdPipeCodec::compress() spawns once per call: correct, but
 * for a one-off rather than a flush. ZstdPipeCodec::compressBatch() writes the whole batch to a
 * scratch directory and runs the binary ONCE across every file, which is what compaction,
 * dictionary training and export use. CodecRegistry does not select this codec for the hot path;
 * a host without the extension gets gzip there and this codec for bulk work.
 *
 * ZstdPipeCodec::spawnCount() is the seam the test lane uses to assert that a batch of N buffers
 * costs one process rather than N.
 *
 * @see ZstdCodec
 * @see CodecRegistry
 */
final class ZstdPipeCodec implements CompressionCodecInterface
{
	/**
	 * Seconds a single binary invocation may run before it is killed.
	 */
	private const TIMEOUT = 300;

	/**
	 * Resolved absolute path to the binary, or NULL when it has not been looked up yet.
	 */
	private ?string $binary = null;

	/**
	 * Whether the lookup has run, so a negative result is not retried on every call.
	 */
	private bool $resolved = false;

	/**
	 * How many processes this instance has spawned.
	 */
	private int $spawns = 0;

	/**
	 * Constructs the codec.
	 *
	 * @param string|null $binaryPath
	 *   An explicit path to the `zstd` binary, or NULL to search PATH. An explicit path is what a
	 *   site with a non-standard install sets in configuration.
	 * @param string|null $scratchDirectory
	 *   Directory for batch scratch files, or NULL for the system temporary directory.
	 */
	public function __construct(
		private readonly ?string $binaryPath = null,
		private readonly ?string $scratchDirectory = null,
	) {}

	/**
	 * {@inheritdoc}
	 */
	public function id(): string
	{
		// the same on-disk format as ZstdCodec, so a frame written by either is readable by both
		return 'zstd';
	}

	/**
	 * {@inheritdoc}
	 */
	public function isAvailable(): bool
	{
		return $this->resolveBinary() !== null && $this->canSpawn();
	}

	/**
	 * {@inheritdoc}
	 */
	public function unavailableReason(): ?string
	{
		if (!$this->canSpawn()) {
			return 'proc_open() is disabled, so the zstd binary cannot be run';
		}
		if ($this->resolveBinary() === null) {
			return 'the zstd binary was not found on PATH';
		}

		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function supportsDictionary(): bool
	{
		return $this->isAvailable();
	}

	/**
	 * {@inheritdoc}
	 */
	public function levels(): array
	{
		return ['min' => 1, 'max' => 19, 'default' => 3, 'fast' => 1, 'dense' => 19];
	}

	/**
	 * How many processes this instance has spawned since construction.
	 *
	 * @internal
	 *   Exists so the test lane can assert that a batch costs one spawn rather than one per buffer.
	 *
	 * @return int
	 *   The spawn count.
	 */
	public function spawnCount(): int
	{
		return $this->spawns;
	}

	/**
	 * {@inheritdoc}
	 */
	public function compress(string $data, ?int $level = null, ?string $dictionary = null): string
	{
		if ($data === '') {
			return '';
		}

		return $this->batch([$data], true, $level, $dictionary)[0];
	}

	/**
	 * {@inheritdoc}
	 */
	public function decompress(string $data, ?string $dictionary = null): string
	{
		if ($data === '') {
			return '';
		}

		return $this->batch([$data], false, null, $dictionary)[0];
	}

	/**
	 * Compresses many buffers in one process.
	 *
	 * @param list<string> $buffers
	 *   The buffers to compress, in order.
	 * @param int|null $level
	 *   A level within ZstdPipeCodec::levels(), or NULL for the default.
	 * @param string|null $dictionary
	 *   Raw dictionary bytes, or NULL.
	 *
	 * @return list<string>
	 *   The compressed buffers, in the same order.
	 *
	 * @throws RuntimeException
	 *   When the codec is unavailable, or the binary fails.
	 */
	public function compressBatch(
		array $buffers,
		?int $level = null,
		?string $dictionary = null,
	): array {
		return $this->batch($buffers, true, $level, $dictionary);
	}

	/**
	 * Decompresses many buffers in one process.
	 *
	 * @param list<string> $buffers
	 *   The buffers to decompress, in order.
	 * @param string|null $dictionary
	 *   The same dictionary bytes used to compress, or NULL.
	 *
	 * @return list<string>
	 *   The original buffers, in the same order.
	 *
	 * @throws RuntimeException
	 *   When the codec is unavailable, or the binary fails.
	 */
	public function decompressBatch(array $buffers, ?string $dictionary = null): array
	{
		return $this->batch($buffers, false, null, $dictionary);
	}

	/**
	 * Runs one binary invocation across a whole batch.
	 *
	 * @param list<string> $buffers
	 *   The buffers to process, in order.
	 * @param bool $compressing
	 *   TRUE to compress, FALSE to decompress.
	 * @param int|null $level
	 *   Compression level, ignored when decompressing.
	 * @param string|null $dictionary
	 *   Raw dictionary bytes, or NULL.
	 *
	 * @return list<string>
	 *   The processed buffers, in the same order.
	 *
	 * @throws RuntimeException
	 *   When the codec is unavailable, or the binary fails.
	 */
	private function batch(
		array $buffers,
		bool $compressing,
		?int $level,
		?string $dictionary,
	): array {
		if ($buffers === []) {
			return [];
		}
		$this->assertAvailable();

		$scratch = $this->makeScratchDirectory();

		try {
			$inputs = [];
			$outputs = [];

			foreach (array_values($buffers) as $index => $buffer) {
				$in = $scratch . '/' . $index . ($compressing ? '.raw' : '.zst');
				file_put_contents($in, $buffer);
				$inputs[] = $in;
				$outputs[] = $compressing ? $in . '.zst' : $scratch . '/' . $index . '.zst.out';
			}

			$command = [(string) $this->resolveBinary(), '-q', '-f'];
			if ($compressing) {
				$command[] = '-' . $this->clamp($level);
			} else {
				$command[] = '-d';
			}

			$dictionaryPath = null;
			if ($dictionary !== null && $dictionary !== '') {
				$dictionaryPath = $scratch . '/dictionary.zdict';
				file_put_contents($dictionaryPath, $dictionary);
				$command[] = '-D';
				$command[] = $dictionaryPath;
			}

			if ($compressing) {
				// zstd writes <name>.zst beside each input when given several files
				array_push($command, ...$inputs);
			} else {
				// decompression needs an explicit output directory to stay predictable
				$command[] = '--output-dir-flat';
				$command[] = $scratch . '/out';
				mkdir($scratch . '/out', 0700, true);
				array_push($command, ...$inputs);
				$outputs = array_map(
					static fn(int $i): string => $scratch . '/out/' . $i,
					array_keys($inputs),
				);
			}

			$this->run($command);

			$result = [];
			foreach ($outputs as $index => $path) {
				if (!is_file($path)) {
					throw new RuntimeException(
						sprintf(
							'zstd produced no output for buffer %d; the input is not valid for this mode',
							$index,
						),
					);
				}
				$bytes = file_get_contents($path);
				if ($bytes === false) {
					throw new RuntimeException(
						sprintf('Cannot read zstd output for buffer %d', $index),
					);
				}
				$result[] = $bytes;
			}

			return $result;
		} finally {
			$this->removeDirectory($scratch);
		}
	}

	/**
	 * Executes one command and fails loudly on a non-zero exit.
	 *
	 * @param list<string> $command
	 *   The argument vector. Never a shell string, so a path with a space or a quote cannot become
	 *   an injection.
	 *
	 * @throws RuntimeException
	 *   When the process cannot start, times out, or exits non-zero.
	 */
	private function run(array $command): void
	{
		$descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
		$process = proc_open($command, $descriptors, $pipes);

		if (!is_resource($process)) {
			throw new RuntimeException('Cannot start the zstd binary');
		}

		$this->spawns++;
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stderr = '';
		$deadline = time() + self::TIMEOUT;

		while (true) {
			$status = proc_get_status($process);
			$stderr .= (string) stream_get_contents($pipes[2]);
			stream_get_contents($pipes[1]);

			if (!$status['running']) {
				$exit = $status['exitcode'];
				break;
			}
			if (time() > $deadline) {
				proc_terminate($process, 9);
				fclose($pipes[1]);
				fclose($pipes[2]);
				proc_close($process);

				throw new RuntimeException(
					sprintf('zstd timed out after %d seconds', self::TIMEOUT),
				);
			}

			usleep(1000);
		}

		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		if ($exit !== 0) {
			throw new RuntimeException(
				sprintf(
					'zstd exited %d: %s',
					$exit,
					trim($stderr) === '' ? 'no error output' : trim($stderr),
				),
			);
		}
	}

	/**
	 * Brings a requested level into the supported range.
	 *
	 * @param int|null $level
	 *   The requested level, or NULL for the default.
	 *
	 * @return int
	 *   A level this codec accepts.
	 */
	private function clamp(?int $level): int
	{
		$levels = $this->levels();

		if ($level === null) {
			return $levels['default'];
		}

		return max($levels['min'], min($levels['max'], $level));
	}

	/**
	 * Locates the binary once and caches the answer, including a negative one.
	 *
	 * @return string|null
	 *   An absolute path, or NULL when the binary is not present.
	 */
	private function resolveBinary(): ?string
	{
		if ($this->resolved) {
			return $this->binary;
		}

		$this->resolved = true;

		if ($this->binaryPath !== null) {
			$this->binary = is_executable($this->binaryPath) ? $this->binaryPath : null;

			return $this->binary;
		}

		foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
			$candidate = rtrim($directory, '/') . '/zstd';
			if ($candidate !== '/zstd' && is_executable($candidate)) {
				$this->binary = $candidate;

				return $this->binary;
			}
		}

		return null;
	}

	/**
	 * Whether this PHP is allowed to start a process at all.
	 *
	 * @return bool
	 *   TRUE when proc_open() is neither missing nor in disable_functions.
	 */
	private function canSpawn(): bool
	{
		if (!function_exists('proc_open')) {
			return false;
		}

		$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

		return !in_array('proc_open', $disabled, true);
	}

	/**
	 * Creates a private scratch directory for one batch.
	 *
	 * @return string
	 *   An absolute path with no trailing slash.
	 *
	 * @throws RuntimeException
	 *   When the directory cannot be created.
	 */
	private function makeScratchDirectory(): string
	{
		$base = $this->scratchDirectory ?? sys_get_temp_dir();
		$path = rtrim($base, '/') . '/strata-zstd-' . bin2hex(random_bytes(8));

		if (!mkdir($path, 0700, true) && !is_dir($path)) {
			throw new RuntimeException(
				sprintf('Cannot create the zstd scratch directory %s', $path),
			);
		}

		return $path;
	}

	/**
	 * Removes a scratch directory and everything under it.
	 *
	 * @param string $path
	 *   The directory to remove.
	 */
	private function removeDirectory(string $path): void
	{
		foreach (glob($path . '/*') ?: [] as $entry) {
			if (is_dir($entry)) {
				$this->removeDirectory($entry);

				continue;
			}
			@unlink($entry);
		}

		@rmdir($path);
	}

	/**
	 * Guards every entry point.
	 *
	 * @throws RuntimeException
	 *   When the binary cannot be run.
	 */
	private function assertAvailable(): void
	{
		if (!$this->isAvailable()) {
			throw new RuntimeException(
				sprintf(
					'zstd binary codec is unavailable: %s',
					(string) $this->unavailableReason(),
				),
			);
		}
	}
}
