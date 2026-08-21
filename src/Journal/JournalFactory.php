<?php

declare(strict_types=1);

namespace Drupal\strata\Journal;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Psr\Log\LoggerInterface;
use Redis;
use RuntimeException;
use Throwable;

/**
 * Chooses the journal backend, and falls back rather than failing.
 *
 * A journal that cannot be constructed would take capture down with it, and capture is the one thing
 * that must not stop: an operation not captured is a gap in the history that nothing can fill later.
 * So a configured backend that cannot be reached logs why and hands back the database journal, which
 * needs nothing beyond what Drupal already has.
 *
 * The Redis connection is read from Drupal's own `redis.connection` settings, which is where
 * `drupal/redis` puts it, so a site already running Redis configures nothing extra.
 *
 * @see JournalInterface
 * @see RedisJournal
 * @see DatabaseJournal
 */
final class JournalFactory
{
	/**
	 * The always-available backend.
	 */
	public const DATABASE = 'database';

	/**
	 * The Redis stream backend.
	 */
	public const REDIS = 'redis';

	/**
	 * Default Redis host, matching what `drupal/redis` assumes.
	 */
	public const DEFAULT_HOST = '127.0.0.1';

	/**
	 * Default Redis port.
	 */
	public const DEFAULT_PORT = 6379;

	/**
	 * Seconds to wait for a Redis connection.
	 *
	 * Short, because this runs while a request is being served and a journal that hangs is worse than
	 * one that falls back.
	 */
	public const TIMEOUT = 1.0;

	/**
	 * Constructs a factory.
	 *
	 * @param ConfigFactoryInterface $configFactory
	 *   Where the configured backend is read from.
	 * @param Connection $database
	 *   Backs the database journal.
	 * @param LoggerInterface $logger
	 *   Records a backend that could not be used.
	 * @param object|null $client
	 *   A ready Redis client, which a test supplies. NULL to connect from settings.
	 */
	public function __construct(
		private readonly ConfigFactoryInterface $configFactory,
		private readonly Connection $database,
		private readonly LoggerInterface $logger,
		private readonly ?object $client = null,
	) {}

	/**
	 * The journal this site should use.
	 *
	 * @return JournalInterface
	 *   The journal.
	 */
	public function create(): JournalInterface
	{
		$settings = $this->configFactory->get('strata.settings');
		$backend = (string) ($settings->get('journal.backend') ?? self::DATABASE);

		if ($backend !== self::REDIS) {
			return new DatabaseJournal($this->database);
		}

		$stream = (string) ($settings->get('journal.redis_stream') ?? RedisJournal::STREAM);
		$client = $this->client ?? $this->connect();

		if ($client === null) {
			return new DatabaseJournal($this->database);
		}

		try {
			return new RedisJournal($client, $stream === '' ? RedisJournal::STREAM : $stream);
		} catch (Throwable $error) {
			$this->logger->warning(
				'Strata is configured for a Redis journal but is using the database one: %message',
				['%message' => $error->getMessage()],
			);

			return new DatabaseJournal($this->database);
		}
	}

	/**
	 * Which backend is actually in use.
	 *
	 * Shown on the status page, because "configured for Redis" and "using Redis" are different
	 * statements and an operator needs the second one.
	 *
	 * @return string
	 *   A backend id.
	 */
	public function active(): string
	{
		return $this->create() instanceof RedisJournal ? self::REDIS : self::DATABASE;
	}

	/**
	 * A Redis client from Drupal's own connection settings.
	 *
	 * @return object|null
	 *   The client, or NULL when the extension is absent or the connection failed.
	 */
	private function connect(): ?object
	{
		if (!RedisJournal::isSupported()) {
			$this->logger->warning(
				'Strata is configured for a Redis journal but ext-redis is not loaded, so the database journal is in use',
			);

			return null;
		}

		/** @var array<string, mixed> $connection */
		$connection = Settings::get('redis.connection', []);

		try {
			$client = new Redis();
			$connected = $client->connect(
				(string) ($connection['host'] ?? self::DEFAULT_HOST),
				(int) ($connection['port'] ?? self::DEFAULT_PORT),
				self::TIMEOUT,
			);

			if ($connected !== true) {
				throw new RuntimeException('the connection was refused');
			}

			$password = $connection['password'] ?? null;

			if (is_string($password) && $password !== '') {
				$client->auth($password);
			}

			return $client;
		} catch (Throwable $error) {
			$this->logger->warning(
				'Strata could not reach Redis for the journal, so the database journal is in use: %message',
				['%message' => $error->getMessage()],
			);

			return null;
		}
	}
}
