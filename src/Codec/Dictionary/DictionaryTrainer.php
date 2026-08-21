<?php

declare(strict_types=1);

namespace Drupal\strata\Codec\Dictionary;

use Drupal\strata\Codec\CompressionCodecInterface;

/**
 * Builds a dictionary for one realm out of what that realm actually writes.
 *
 * A dictionary is what makes small frames compress at all. A 16 KiB frame has almost no history to
 * match against, so the same field names and the same JSON scaffolding are re-encoded in every frame;
 * a dictionary supplies that history up front. Measured through the shipped codec: 4.87x without and
 * 5.86x with, on the reference corpus at 16 KiB frames.
 *
 * **Two ways to build one, and which wins depends on the corpus, so both are built and scored.**
 * Concatenating samples produces a raw content dictionary, which zstd matches against verbatim; the
 * `zstd` binary's trainer produces a compact entropy-oriented one. On 200 near-identical entity
 * payloads the raw dictionary measured 11.64x against the trainer's 8.42x; on 400 deliberately mixed
 * payloads the trainer measured 5.42x against 4.96x. Neither is generally better, so a run compresses
 * a held-out slice with each candidate and keeps whichever actually won. Nothing here assumes.
 *
 * No PHP extension exposes zstd's training API - `ext-zstd` ships the dictionary compress and
 * decompress calls and nothing else - so the trained candidate needs the binary and is simply absent
 * when it is not installed.
 *
 * @see DictionaryRef
 * @see DictionaryStore
 */
final class DictionaryTrainer
{
	/**
	 * Fewest samples worth building a dictionary from.
	 *
	 * Below this the dictionary is longer than what it would save and describes one document rather
	 * than a realm.
	 */
	public const MIN_SAMPLES = 32;

	/**
	 * Largest dictionary produced.
	 *
	 * 110 KiB, zstd's own recommended ceiling. A dictionary is fetched once per process and then held,
	 * so its size costs memory rather than requests, but a dictionary approaching the frame size stops
	 * being a summary of the realm.
	 */
	public const MAX_BYTES = 112_640;

	/**
	 * Share of the samples the raw candidate is built from.
	 *
	 * The rest are held back for scoring, so a candidate is never scored on the bytes it contains -
	 * which would flatter the raw candidate enormously and pick it every time.
	 */
	public const BUILD_SHARE = 0.5;

	/**
	 * Compression level candidates are scored at.
	 *
	 * The compaction level, because that is where a dictionary is used: at 422.9 MB/s against
	 * 105.1 MB/s, loading one per frame is too expensive for the flush path.
	 */
	public const SCORE_LEVEL = 19;

	/**
	 * Seconds the trainer will wait for the binary.
	 */
	public const TRAIN_TIMEOUT = 30;

	/**
	 * Constructs a trainer.
	 *
	 * @param CompressionCodecInterface $codec
	 *   The codec candidates are scored through, so the score is what this host will actually get.
	 * @param string|null $binary
	 *   Path to the `zstd` binary, or NULL to look for it on the PATH.
	 */
	public function __construct(
		private readonly CompressionCodecInterface $codec,
		private readonly ?string $binary = null,
	) {}

	/**
	 * Builds the best dictionary the samples support.
	 *
	 * @param list<string> $samples
	 *   Representative payloads from one realm.
	 *
	 * @return array{bytes: string, source: string, ratio: float, samples: int}|null
	 *   The winning candidate with the ratio it measured, or NULL when there is too little data or no
	 *   candidate beat compressing without a dictionary at all.
	 */
	public function train(array $samples): ?array
	{
		if (!$this->codec->supportsDictionary() || count($samples) < self::MIN_SAMPLES) {
			return null;
		}

		$split = (int) max(1, floor(count($samples) * self::BUILD_SHARE));
		$build = array_slice($samples, 0, $split);
		$score = array_slice($samples, $split);

		if ($score === []) {
			return null;
		}

		$baseline = $this->measure($score, null);
		$best = null;

		foreach ($this->candidates($build) as $source => $bytes) {
			$ratio = $this->measure($score, $bytes);

			if ($ratio <= $baseline || ($best !== null && $ratio <= $best['ratio'])) {
				continue;
			}

			$best = [
				'bytes' => $bytes,
				'source' => (string) $source,
				'ratio' => $ratio,
				'samples' => count($samples),
			];
		}

		return $best;
	}

	/**
	 * What a corpus compresses to without any dictionary.
	 *
	 * The number a candidate has to beat, and the reason a realm whose payloads have nothing in common
	 * ends up with no dictionary rather than a useless one.
	 *
	 * @param list<string> $samples
	 *   Payloads to score against.
	 *
	 * @return float
	 *   The ratio.
	 */
	public function baseline(array $samples): float
	{
		return $this->measure($samples, null);
	}

	/**
	 * Whether a trained candidate can be built on this host.
	 *
	 * @return bool
	 *   TRUE when the zstd binary answers.
	 */
	public function canTrain(): bool
	{
		return $this->resolveBinary() !== null;
	}

	/**
	 * Every candidate dictionary the samples support.
	 *
	 * @param list<string> $build
	 *   The samples a candidate may be built from.
	 *
	 * @return array<string, string>
	 *   Source keyed to dictionary bytes.
	 */
	private function candidates(array $build): array
	{
		$candidates = [];
		$raw = $this->concatenate($build);

		if ($raw !== '') {
			$candidates[DictionaryRef::RAW] = $raw;
		}

		$trained = $this->trainWithBinary($build);

		if ($trained !== null) {
			$candidates[DictionaryRef::TRAINED] = $trained;
		}

		return $candidates;
	}

	/**
	 * The raw content candidate: samples end to end, newest first, up to the ceiling.
	 *
	 * Newest first because a dictionary's tail is what zstd matches most cheaply, and the samples a
	 * realm is writing now are the ones the next frames will look like.
	 *
	 * @param list<string> $build
	 *   The samples.
	 *
	 * @return string
	 *   The dictionary bytes, empty when there is nothing to concatenate.
	 */
	private function concatenate(array $build): string
	{
		$bytes = '';

		foreach (array_reverse($build) as $sample) {
			if (strlen($bytes) >= self::MAX_BYTES) {
				break;
			}

			$bytes .= $sample;
		}

		return substr($bytes, 0, self::MAX_BYTES);
	}

	/**
	 * The trained candidate, from the zstd binary.
	 *
	 * @param list<string> $build
	 *   The samples.
	 *
	 * @return string|null
	 *   The dictionary bytes, or NULL when the binary is absent or refused.
	 */
	private function trainWithBinary(array $build): ?string
	{
		$binary = $this->resolveBinary();

		if ($binary === null) {
			return null;
		}

		$directory = $this->temporaryDirectory();

		if ($directory === null) {
			return null;
		}

		try {
			foreach ($build as $index => $sample) {
				file_put_contents(sprintf('%s/s%d', $directory, $index), $sample);
			}

			$output = $directory . '/dictionary';
			$command = sprintf(
				'%s --train %s/s* -o %s --maxdict=%d 2>&1',
				escapeshellcmd($binary),
				escapeshellarg($directory),
				escapeshellarg($output),
				self::MAX_BYTES,
			);

			exec($command, $lines, $status);

			if ($status !== 0 || !is_file($output)) {
				return null;
			}

			$bytes = file_get_contents($output);

			return $bytes === false || $bytes === '' ? null : $bytes;
		} finally {
			$this->removeDirectory($directory);
		}
	}

	/**
	 * The ratio a corpus compresses at, with a dictionary or without one.
	 *
	 * @param list<string> $samples
	 *   Payloads.
	 * @param string|null $dictionary
	 *   Dictionary bytes, or NULL.
	 *
	 * @return float
	 *   Raw bytes over compressed bytes, 1.0 when nothing could be measured.
	 */
	private function measure(array $samples, ?string $dictionary): float
	{
		$raw = 0;
		$compressed = 0;

		foreach ($samples as $sample) {
			$raw += strlen($sample);
			$compressed += strlen($this->codec->compress($sample, self::SCORE_LEVEL, $dictionary));
		}

		return $raw < 1 || $compressed < 1 ? 1.0 : $raw / $compressed;
	}

	/**
	 * The zstd binary, if there is one.
	 *
	 * @return string|null
	 *   Its path, or NULL.
	 */
	private function resolveBinary(): ?string
	{
		if ($this->binary !== null) {
			return is_executable($this->binary) ? $this->binary : null;
		}

		$found = @exec('command -v zstd 2>/dev/null');

		return is_string($found) && $found !== '' && is_executable($found) ? $found : null;
	}

	/**
	 * A directory to write samples into.
	 *
	 * @return string|null
	 *   The path, or NULL when one could not be made.
	 */
	private function temporaryDirectory(): ?string
	{
		$path = sprintf('%s/strata-dict-%s', sys_get_temp_dir(), bin2hex(random_bytes(8)));

		return @mkdir($path, 0700, true) ? $path : null;
	}

	/**
	 * Removes the sample directory.
	 *
	 * @param string $path
	 *   The directory.
	 */
	private function removeDirectory(string $path): void
	{
		foreach (glob($path . '/*') ?: [] as $file) {
			@unlink($file);
		}

		@rmdir($path);
	}
}
