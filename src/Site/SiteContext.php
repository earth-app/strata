<?php

declare(strict_types=1);

namespace Drupal\strata\Site;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\strata\Cas\Hash;

/**
 * Which site's history a read or a write belongs to.
 *
 * Several sites can share one bucket, and often should: dedup is per-frame and content-addressed, so
 * two sites running the same modules share the frames for their identical config and code, and the
 * second site's code realm costs almost nothing. What they must not share is history. A commit is a
 * statement about one site at one instant, and a ref that two sites both advanced would describe
 * neither.
 *
 * So every key is prefixed with a site id and every ref lives under it. A restore reads only its own
 * site's refs; a prune walks only its own site's commits. Frames stay shared, because a frame is
 * content and content has no owner.
 *
 * **The site id is derived once and then fixed.** It defaults to a digest of the database connection's
 * own identity - the database name and the table prefix - because that is what actually distinguishes
 * two sites sharing a codebase, and because it does not change when a domain does. A site that moves
 * to a new database keeps its history by setting the id explicitly, which is exactly the case where
 * deriving it again would silently start a second history.
 *
 * @see SiteRegistry
 */
final class SiteContext
{
	/**
	 * How many hex characters of the derived digest the id uses.
	 *
	 * Short enough to keep keys readable, long enough that two sites in one bucket will not collide.
	 */
	public const ID_LENGTH = 12;

	/**
	 * The resolved id, or NULL until first asked.
	 */
	private ?string $id = null;

	/**
	 * Constructs a context.
	 *
	 * @param ConfigFactoryInterface $configFactory
	 *   Where a configured site id is read from.
	 * @param Connection $database
	 *   Used to derive an id when none is configured.
	 */
	public function __construct(
		private readonly ConfigFactoryInterface $configFactory,
		private readonly Connection $database,
	) {}

	/**
	 * This site's id.
	 *
	 * @return string
	 *   The configured id, or one derived from the database connection.
	 */
	public function id(): string
	{
		if ($this->id !== null) {
			return $this->id;
		}

		$configured = self::normalise(
			(string) $this->configFactory->get('strata.settings')->get('site_id'),
		);

		return $this->id = $configured === '' ? $this->derive() : $configured;
	}

	/**
	 * Whether the id was configured rather than derived.
	 *
	 * Shown on the status page, because a derived id is one that would change if the database moved
	 * and a configured one is not.
	 *
	 * @return bool
	 *   TRUE when a site id is set in configuration.
	 */
	public function isExplicit(): bool
	{
		return self::normalise(
			(string) $this->configFactory->get('strata.settings')->get('site_id'),
		) !== '';
	}

	/**
	 * The key prefix every object for this site sits under.
	 *
	 * @return string
	 *   Something such as "a1b2c3d4e5f6/".
	 */
	public function prefix(): string
	{
		return $this->id() . '/';
	}

	/**
	 * A key inside this site's namespace.
	 *
	 * @param string $key
	 *   The key relative to the site, such as "commits/aa/bb/cc".
	 *
	 * @return string
	 *   The full key.
	 */
	public function key(string $key): string
	{
		return $this->prefix() . ltrim($key, '/');
	}

	/**
	 * Strips this site's prefix off a key.
	 *
	 * @param string $key
	 *   The full key.
	 *
	 * @return string|null
	 *   The key relative to the site, or NULL when the key belongs to a different site. Returning
	 *   NULL rather than the key unchanged is what stops a listing over a shared bucket quietly
	 *   handing one site another's commits.
	 */
	public function strip(string $key): ?string
	{
		$prefix = $this->prefix();

		return str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : null;
	}

	/**
	 * Forgets the resolved id.
	 *
	 * Called after a settings change, and by a test that switches sites mid-run.
	 */
	public function reset(): void
	{
		$this->id = null;
	}

	/**
	 * An id derived from the database connection's identity.
	 *
	 * The database name and table prefix together are what distinguishes two Drupal sites sharing a
	 * codebase, which is the multi-site case this exists for. A domain is not used: it changes when a
	 * site is renamed or moved behind a different host, and a changed site id means a second history.
	 *
	 * @return string
	 *   The derived id.
	 */
	private function derive(): string
	{
		$options = $this->database->getConnectionOptions();
		$identity = implode('|', [
			(string) ($options['database'] ?? ''),
			(string) ($options['prefix'] ?? ''),
			(string) ($options['host'] ?? ''),
		]);

		return substr(Hash::of($identity), 0, self::ID_LENGTH);
	}

	/**
	 * Reduces a configured id to something safe in an object key.
	 *
	 * A site id ends up in every key this site writes, so a slash or a traversal segment in it would
	 * let one site write into another's namespace.
	 *
	 * @param string $id
	 *   The configured id.
	 *
	 * @return string
	 *   The id with everything but letters, digits, hyphens and underscores removed.
	 */
	public static function normalise(string $id): string
	{
		return (string) preg_replace('/[^A-Za-z0-9_-]/', '', trim($id));
	}
}
