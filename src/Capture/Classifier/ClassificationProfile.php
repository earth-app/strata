<?php

declare(strict_types=1);

namespace Drupal\strata\Capture\Classifier;

use InvalidArgumentException;
use JsonSerializable;

/**
 * A portable set of classification decisions.
 *
 * Deciding what a site's ephemeral keyspace is for is real work, done once per site by someone who
 * understands the modules on it. Two things make that work worth exporting: a staging site and a
 * production site running the same modules need the same answers, and a module maintainer can ship
 * the answers for their own namespaces so nobody has to rediscover them.
 *
 * Only decisions travel. Observed key and byte counts are properties of one site's traffic and mean
 * nothing on another, so they are left behind rather than carried across and believed.
 *
 * An imported profile does not overwrite a local human decision by default. Someone who looked at
 * their own site and chose an answer outranks a file, and a profile that silently reversed that
 * would be worse than no profile at all.
 *
 * @see ClassificationRegistry
 */
final class ClassificationProfile implements JsonSerializable
{
	/**
	 * Format version written into every profile.
	 */
	public const VERSION = 1;

	/**
	 * Constructs a profile.
	 *
	 * @param array<string, Classification> $decisions
	 *   Pattern keyed to its classification.
	 * @param string $label
	 *   What this profile is, such as "earth-app production" or "commerce defaults".
	 * @param int $version
	 *   Format version.
	 */
	public function __construct(
		public readonly array $decisions = [],
		public readonly string $label = '',
		public readonly int $version = self::VERSION,
	) {
		if ($version < 1) {
			throw new InvalidArgumentException('A classification profile needs a version');
		}

		foreach (array_keys($decisions) as $pattern) {
			if (trim((string) $pattern) === '') {
				throw new InvalidArgumentException(
					'A classification profile cannot hold an empty pattern',
				);
			}
		}
	}

	/**
	 * Builds a profile from what a registry holds.
	 *
	 * @param ClassificationRegistry $registry
	 *   The registry to read.
	 * @param string $label
	 *   What to call the profile.
	 * @param bool $humanOnly
	 *   TRUE to export only what a person decided, which is the useful default: a heuristic's answer
	 *   is re-derived on the target site anyway, and exporting it would freeze this release's rules
	 *   into a file that outlives them.
	 *
	 * @return self
	 *   The profile.
	 */
	public static function fromRegistry(
		ClassificationRegistry $registry,
		string $label = '',
		bool $humanOnly = true,
	): self {
		$decisions = [];

		foreach ($registry->all() as $pattern => $row) {
			if ($humanOnly && $row['source'] !== Heuristics::HUMAN) {
				continue;
			}

			$decisions[$pattern] = $row['classification'];
		}

		return new self($decisions, $label);
	}

	/**
	 * Applies this profile to a registry.
	 *
	 * @param ClassificationRegistry $registry
	 *   The registry to write into.
	 * @param bool $overwriteLocal
	 *   TRUE to replace a decision a person made on this site. FALSE, the default, keeps it.
	 *
	 * @return array{applied: int, kept: int}
	 *   How many decisions were written and how many local ones were left alone.
	 */
	public function applyTo(ClassificationRegistry $registry, bool $overwriteLocal = false): array
	{
		$applied = 0;
		$kept = 0;

		foreach ($this->decisions as $pattern => $classification) {
			if (!$overwriteLocal && $registry->isDecidedByHuman($pattern)) {
				$kept++;

				continue;
			}

			$registry->decide($pattern, $classification);
			$applied++;
		}

		return ['applied' => $applied, 'kept' => $kept];
	}

	/**
	 * How many decisions the profile carries.
	 *
	 * @return int
	 *   The count.
	 */
	public function count(): int
	{
		return count($this->decisions);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string, mixed>
	 *   The profile as a plain array, which is the form written to a file.
	 */
	public function jsonSerialize(): array
	{
		$decisions = [];

		foreach ($this->decisions as $pattern => $classification) {
			$decisions[$pattern] = $classification->value;
		}

		return ['version' => $this->version, 'label' => $this->label, 'decisions' => $decisions];
	}

	/**
	 * Reads a profile back.
	 *
	 * A pattern whose classification this release does not know is skipped rather than guessed at,
	 * so a profile written by a later version loads what it can and says nothing about the rest.
	 *
	 * @param array<string, mixed> $data
	 *   The array produced by ClassificationProfile::jsonSerialize().
	 *
	 * @return self
	 *   The profile.
	 *
	 * @throws InvalidArgumentException
	 *   When the version is one this release cannot read.
	 */
	public static function fromArray(array $data): self
	{
		$version = (int) ($data['version'] ?? 0);

		if ($version !== self::VERSION) {
			throw new InvalidArgumentException(
				sprintf(
					'This release reads version %d classification profiles, not version %d',
					self::VERSION,
					$version,
				),
			);
		}

		$decisions = [];

		/** @var array<string, mixed> $raw */
		$raw = is_array($data['decisions'] ?? null) ? $data['decisions'] : [];

		foreach ($raw as $pattern => $value) {
			$classification = Classification::tryFrom((string) $value);

			if ($classification !== null) {
				$decisions[(string) $pattern] = $classification;
			}
		}

		return new self($decisions, (string) ($data['label'] ?? ''), $version);
	}

	/**
	 * Reads a profile from a JSON file.
	 *
	 * @param string $path
	 *   The file path.
	 *
	 * @return self
	 *   The profile.
	 *
	 * @throws InvalidArgumentException
	 *   When the file cannot be read or does not hold a profile.
	 */
	public static function fromFile(string $path): self
	{
		$contents = @file_get_contents($path);

		if ($contents === false) {
			throw new InvalidArgumentException(sprintf('Cannot read %s', $path));
		}

		/** @var array<string, mixed>|null $decoded */
		$decoded = json_decode($contents, true);

		if (!is_array($decoded)) {
			throw new InvalidArgumentException(sprintf('%s does not hold a JSON profile', $path));
		}

		return self::fromArray($decoded);
	}
}
