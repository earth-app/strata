<?php

declare(strict_types=1);

namespace Drupal\strata_redis;

use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Redis;
use RuntimeException;
use Throwable;

/**
 * Resolves a Redis client, or reports why there is not one.
 *
 * Redis being absent is the normal case rather than a fault. `ext-redis` is a suggest and is not
 * loaded on many hosts, a site can have the extension without having configured a connection, and a
 * connection that worked yesterday can refuse today. Every one of those has to end in a client of
 * NULL and a sentence saying which, because the alternative is an exception thrown out of a cron
 * hook - and a cron hook that throws stops every later hook in the queue.
 *
 * The connection is read from Drupal's own `redis.connection` settings, the same place the journal
 * reads it from, so a site already running Redis configures nothing extra for this.
 *
 * **One attempt per request.** A refused connection costs the connect timeout, and a capture pass
 * that retried per key would spend a minute discovering the same refusal. The answer is resolved
 * once and held, including when the answer is nothing.
 *
 * @see RedisKeyspaceSource
 * @see RedisCapture
 */
final class RedisClientFactory
{
	/**
	 * Settings key the connection is read from.
	 */
	public const SETTINGS = 'redis.connection';

	/**
	 * Default host, matching what the journal assumes.
	 */
	public const DEFAULT_HOST = '127.0.0.1';

	/**
	 * Default port.
	 */
	public const DEFAULT_PORT = 6379;

	/**
	 * Seconds to wait for a connection.
	 *
	 * Short, because a capture pass runs on cron behind every other cron hook and one that hangs on
	 * a dead Redis delays the site's search indexing rather than only itself.
	 */
	public const TIMEOUT = 1.0;

	/**
	 * The resolved client, or NULL when there is not one.
	 */
	private ?object $resolved = null;

	/**
	 * Whether resolution has already been attempted.
	 */
	private bool $attempted = false;

	/**
	 * Why there is no client, or an empty string while there is one.
	 */
	private string $reason = '';

	/**
	 * Constructs a factory.
	 *
	 * @param LoggerInterface $logger
	 *   Records a connection that could not be opened.
	 * @param object|null $client
	 *   A ready client, which a test supplies. NULL to connect from settings.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ?object $client = null,
	) {}

	/**
	 * The client this site can use.
	 *
	 * @return object|null
	 *   A connected client, or NULL when the extension is absent or the connection failed. Typed as
	 *   an object rather than `Redis` so a caller does not require `ext-redis` to be loaded to be
	 *   autoloaded, and so a test can drive it over a stand-in.
	 */
	public function create(): ?object
	{
		if ($this->attempted) {
			return $this->resolved;
		}

		$this->attempted = true;

		if ($this->client !== null) {
			return $this->resolved = $this->client;
		}

		if (!self::isSupported()) {
			$this->reason = 'ext-redis is not loaded on this host';

			return null;
		}

		return $this->resolved = $this->connect();
	}

	/**
	 * Whether a client can be had.
	 *
	 * @return bool
	 *   TRUE when there is one.
	 */
	public function isAvailable(): bool
	{
		return $this->create() !== null;
	}

	/**
	 * Why there is no client.
	 *
	 * Reported rather than logged and forgotten, because "Redis is not being captured" and "Redis
	 * could not be reached" are different statements and an operator needs the second one.
	 *
	 * @return string
	 *   A sentence, or an empty string when there is a client.
	 */
	public function reason(): string
	{
		return $this->create() === null ? $this->reason : '';
	}

	/**
	 * Whether the host can talk to Redis at all.
	 *
	 * @return bool
	 *   TRUE when the extension is loaded.
	 */
	public static function isSupported(): bool
	{
		return extension_loaded('redis');
	}

	/**
	 * Opens a connection from Drupal's own settings.
	 *
	 * @return object|null
	 *   The client, or NULL when the connection failed.
	 */
	private function connect(): ?object
	{
		/** @var array<string, mixed> $connection */
		$connection = Settings::get(self::SETTINGS, []);

		try {
			$client = new Redis();
			$opened = $client->connect(
				(string) ($connection['host'] ?? self::DEFAULT_HOST),
				(int) ($connection['port'] ?? self::DEFAULT_PORT),
				self::TIMEOUT,
			);

			if ($opened !== true) {
				throw new RuntimeException('the connection was refused');
			}

			$password = $connection['password'] ?? null;

			if (is_string($password) && $password !== '') {
				$client->auth($password);
			}

			// the keyspace lives in whichever database the site configured, and scanning the wrong
			// one reports an empty keyspace rather than an error
			$database = $connection['base'] ?? null;

			if (is_numeric($database)) {
				$client->select((int) $database);
			}

			return $client;
		} catch (Throwable $error) {
			$this->reason = sprintf('Redis could not be reached: %s', $error->getMessage());
			$this->logger->warning('Strata is not capturing ephemeral state because %reason', [
				'%reason' => $this->reason,
			]);

			return null;
		}
	}
}
