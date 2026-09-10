<?php

declare(strict_types=1);

namespace Drupal\strata\Codec;

use RuntimeException;

/**
 * Shared machinery for a codec that shells out to a compressor on disk.
 *
 * A PHP extension is a build-time decision and a binary is a package-manager one, so the two are
 * absent on different hosts. `zstd` and `brotli` are both on the PATH of a great many machines whose
 * PHP was built without the matching extension, and reading a frame back matters more than writing
 * one quickly: a bucket written on a box with `ext-brotli` has to stay readable on a box without it,
 * or the backup is not a backup.
 *
 * **Nothing built on this may be a per-frame writer.** A process spawn dominates the work at frame
 * sizes - 1,000 separate `zstd` invocations measured 5.88 seconds against sub-millisecond
 * compression - so `CodecRegistry::register()` takes these with `$perFrame` FALSE, and they serve
 * reading, compaction, dictionary training and export instead. PipeCodec::compress() spawns once
 * per call and is correct for a one-off; PipeCodec::compressBatch() runs the binary ONCE across the
 * whole batch, which is what bulk work uses.
 *
 * Both binaries follow the same file convention, which is what lets one implementation drive them:
 * given several inputs they write beside each one, adding the suffix when compressing and stripping
 * it when decompressing. A batch is therefore laid out as `<n>` and `<n><suffix>` in a private
 * scratch directory and read back by name.
 *
 * @see ZstdPipeCodec
 * @see BrotliPipeCodec
 * @see CodecRegistry::register()
 */
abstract class PipeCodec implements CompressionCodecInterface
{
	/**
	 * Seconds a single binary invocation may run before it is killed.
	 */
	private const TIMEOUT = 300;

	/**
	 * Directories searched after PATH.
	 *
	 * A php-fpm pool frequently runs with a minimal PATH that a login shell does not have, so a
	 * binary the operator can see is regularly one PHP cannot. These are where the common package
	 * managers put one.
	 */
	private const COMMON_DIRECTORIES = [
		'/usr/bin',
		'/usr/local/bin',
		'/bin',
		'/opt/homebrew/bin',
		'/opt/local/bin',
		'/snap/bin',
	];

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
	 *   An explicit path to the binary, or NULL to search PATH and the common directories.
	 * @param string|null $scratchDirectory
	 *   Directory for batch scratch files, or NULL for the system temporary directory.
	 */
	public function __construct(
		private readonly ?string $binaryPath = null,
		private readonly ?string $scratchDirectory = null,
	) {}

	/**
	 * The executable this codec drives, as it is named on the PATH.
	 *
	 * @return string
	 *   The file name, with no directory.
	 */
	abstract protected function binaryName(): string;

	/**
	 * The extension the binary appends to a compressed file.
	 *
	 * @return string
	 *   The suffix, leading dot included.
	 */
	abstract protected function suffix(): string;

	/**
	 * The flags one invocation runs with, before the input paths.
	 *
	 * @param bool $compressing
	 *   TRUE to compress, FALSE to decompress.
	 * @param int $level
	 *   A level already brought into range by PipeCodec::clamp().
	 * @param string|null $dictionaryPath
	 *   Where the dictionary was written, or NULL when there is none.
	 *
	 * @return list<string>
	 *   The arguments, without the binary itself.
	 */
	abstract protected function arguments(
		bool $compressing,
		int $level,
		?string $dictionaryPath,
	): array;

	#region Availability

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
			return sprintf(
				'proc_open() is disabled, so the %s binary cannot be run',
				$this->binaryName(),
			);
		}
		if ($this->resolveBinary() === null) {
			return sprintf('the %s binary was not found on PATH', $this->binaryName());
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
	 * Where the binary was found.
	 *
	 * @internal
	 *   Exists so hook_requirements() can name the path an operator's host actually resolved.
	 *
	 * @return string|null
	 *   An absolute path, or NULL when the binary is not present.
	 */
	public function binaryLocation(): ?string
	{
		return $this->resolveBinary();
	}

	#endregion

	#region Coding

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
	 *   A level within PipeCodec::levels(), or NULL for the default.
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

			// both binaries write beside each input, adding the suffix one way and stripping it the
			// other, so naming the files by index is the whole output mapping
			foreach (array_values($buffers) as $index => $buffer) {
				$plain = $scratch . '/' . $index;
				$coded = $plain . $this->suffix();

				file_put_contents($compressing ? $plain : $coded, $buffer);

				$inputs[] = $compressing ? $plain : $coded;
				$outputs[] = $compressing ? $coded : $plain;
			}

			$dictionaryPath = null;

			if ($dictionary !== null && $dictionary !== '') {
				$dictionaryPath = $scratch . '/dictionary.bin';
				file_put_contents($dictionaryPath, $dictionary);
			}

			$command = array_merge(
				[(string) $this->resolveBinary()],
				$this->arguments($compressing, $this->clamp($level), $dictionaryPath),
				$inputs,
			);

			$this->run($command);

			$result = [];

			foreach ($outputs as $index => $path) {
				if (!is_file($path)) {
					throw new RuntimeException(
						sprintf(
							'%s produced no output for buffer %d; the input is not valid for this mode',
							$this->binaryName(),
							$index,
						),
					);
				}

				$bytes = file_get_contents($path);

				if ($bytes === false) {
					throw new RuntimeException(
						sprintf('Cannot read %s output for buffer %d', $this->binaryName(), $index),
					);
				}

				$result[] = $bytes;
			}

			return $result;
		} finally {
			$this->removeDirectory($scratch);
		}
	}

	#endregion

	#region Process

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
			throw new RuntimeException(sprintf('Cannot start the %s binary', $this->binaryName()));
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
					sprintf('%s timed out after %d seconds', $this->binaryName(), self::TIMEOUT),
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
					'%s exited %d: %s',
					$this->binaryName(),
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

		$name = $this->binaryName();
		$directories = array_merge(
			explode(PATH_SEPARATOR, (string) getenv('PATH')),
			self::COMMON_DIRECTORIES,
		);

		foreach ($directories as $directory) {
			$trimmed = rtrim($directory, '/');

			if ($trimmed === '') {
				continue;
			}

			$candidate = $trimmed . '/' . $name;

			if (is_executable($candidate)) {
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
		$path =
			rtrim($base, '/') . '/strata-' . $this->binaryName() . '-' . bin2hex(random_bytes(8));

		if (!mkdir($path, 0700, true) && !is_dir($path)) {
			throw new RuntimeException(
				sprintf('Cannot create the %s scratch directory %s', $this->binaryName(), $path),
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
					'%s binary codec is unavailable: %s',
					$this->binaryName(),
					(string) $this->unavailableReason(),
				),
			);
		}
	}

	#endregion
}
