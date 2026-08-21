<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Throwable;

/**
 * Carries one provider request to an endpoint over the HTTP client Drupal already ships.
 *
 * Every remote provider takes its transport as a callable, so the whole provider is exercised
 * against canned responses with no network and no credentials. This is the one that talks to a real
 * endpoint.
 *
 * A 4xx or 5xx is a normal answer here, not an exception: the provider reads the status itself and
 * decides what it means, since a 404 from a head is absence while a 404 from a get is an error.
 * The client's own exceptions are therefore unwrapped back into a status where they carry a
 * response, and only a genuine transport failure - a refused connection, a timeout, a DNS failure -
 * surfaces as a status of 0 with the reason in the body.
 *
 * @see StorageProviderInterface
 */
final class HttpTransport
{
	/**
	 * Seconds a single request may take before it is abandoned.
	 */
	public const DEFAULT_TIMEOUT = 120;

	/**
	 * Seconds to wait for a connection.
	 */
	public const DEFAULT_CONNECT_TIMEOUT = 10;

	/**
	 * Constructs a transport.
	 *
	 * @param ClientInterface $client
	 *   The HTTP client.
	 * @param int $timeout
	 *   Seconds a request may take.
	 * @param int $connectTimeout
	 *   Seconds to wait for a connection.
	 */
	public function __construct(
		private readonly ClientInterface $client,
		private readonly int $timeout = self::DEFAULT_TIMEOUT,
		private readonly int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
	) {}

	/**
	 * Sends one request.
	 *
	 * @param string $method
	 *   HTTP method.
	 * @param string $url
	 *   Absolute URL.
	 * @param array<string, string> $headers
	 *   Headers, including whatever authorization the caller produced.
	 * @param string|null $body
	 *   Request body, or NULL.
	 *
	 * @return array{status: int, body: string, headers: array<string, string>}
	 *   The response. A status of 0 means the request never reached the endpoint.
	 */
	public function __invoke(
		string $method,
		string $url,
		array $headers = [],
		?string $body = null,
	): array {
		try {
			$response = $this->client->send(new Request($method, $url, $headers, $body), [
				'timeout' => $this->timeout,
				'connect_timeout' => $this->connectTimeout,
				'http_errors' => false,
				'allow_redirects' => false,
			]);
		} catch (RequestException $e) {
			$response = $e->getResponse();

			if ($response === null) {
				return ['status' => 0, 'body' => $e->getMessage(), 'headers' => []];
			}
		} catch (Throwable $e) {
			return ['status' => 0, 'body' => $e->getMessage(), 'headers' => []];
		}

		$flat = [];

		foreach ($response->getHeaders() as $name => $values) {
			$flat[$name] = implode(', ', $values);
		}

		return [
			'status' => $response->getStatusCode(),
			'body' => (string) $response->getBody(),
			'headers' => $flat,
		];
	}
}
