<?php

declare(strict_types=1);

namespace Drupal\strata\Event\Webhook;

/**
 * Signs and verifies a webhook body.
 *
 * The signature covers the timestamp AND the body, joined by a separator that cannot appear in the
 * timestamp. Signing the body alone would let anyone who captured one delivery replay it forever;
 * signing them concatenated without a separator would let a body starting with digits be traded
 * against the timestamp for the same digest.
 *
 * Verification is constant-time and bounded in age. A receiver that skipped the age check would
 * accept a replay of a genuine delivery from any point in the past, which is the whole reason the
 * timestamp is in the signed material.
 *
 * @see WebhookDispatcher
 */
final class WebhookSignature
{
	/**
	 * Header the signature travels in.
	 */
	public const HEADER = 'X-Strata-Signature';

	/**
	 * Header naming the event, so a receiver can route without parsing the body.
	 */
	public const EVENT_HEADER = 'X-Strata-Event';

	/**
	 * Header carrying the delivery id, so a receiver can drop a duplicate.
	 */
	public const DELIVERY_HEADER = 'X-Strata-Delivery';

	/**
	 * The scheme version, so a later algorithm can be told apart from this one.
	 */
	public const VERSION = 'v1';

	/**
	 * Seconds a signature stays acceptable.
	 */
	public const DEFAULT_TOLERANCE = 300;

	/**
	 * The digest algorithm.
	 */
	private const ALGORITHM = 'sha256';

	/**
	 * The header value for a body.
	 *
	 * @param string $body
	 *   The exact bytes that will be sent.
	 * @param string $secret
	 *   The shared secret.
	 * @param int $timestamp
	 *   Unix seconds the delivery was signed at.
	 *
	 * @return string
	 *   A header value of the form `t=<unix>,v1=<hex>`.
	 */
	public static function sign(string $body, string $secret, int $timestamp): string
	{
		return sprintf(
			't=%d,%s=%s',
			$timestamp,
			self::VERSION,
			hash_hmac(self::ALGORITHM, self::payload($body, $timestamp), $secret),
		);
	}

	/**
	 * Whether a header value authenticates a body.
	 *
	 * @param string $header
	 *   The received header value.
	 * @param string $body
	 *   The received body.
	 * @param string $secret
	 *   The shared secret.
	 * @param int $now
	 *   Unix seconds to measure age against.
	 * @param int $tolerance
	 *   Seconds of age to accept.
	 *
	 * @return bool
	 *   TRUE when the signature matches and is inside the tolerance.
	 */
	public static function verify(
		string $header,
		string $body,
		string $secret,
		int $now,
		int $tolerance = self::DEFAULT_TOLERANCE,
	): bool {
		$parts = self::parse($header);

		if ($parts === null) {
			return false;
		}

		[$timestamp, $digest] = $parts;

		if (abs($now - $timestamp) > $tolerance) {
			return false;
		}

		return hash_equals(
			hash_hmac(self::ALGORITHM, self::payload($body, $timestamp), $secret),
			$digest,
		);
	}

	/**
	 * The exact bytes the digest is taken over.
	 *
	 * @param string $body
	 *   The body.
	 * @param int $timestamp
	 *   Unix seconds.
	 *
	 * @return string
	 *   The signed material.
	 */
	private static function payload(string $body, int $timestamp): string
	{
		return $timestamp . '.' . $body;
	}

	/**
	 * Reads a header value.
	 *
	 * @param string $header
	 *   The header value.
	 *
	 * @return array{int, string}|null
	 *   The timestamp and the digest, or NULL when the header is not the expected shape.
	 */
	private static function parse(string $header): ?array
	{
		$timestamp = null;
		$digest = null;

		foreach (explode(',', $header) as $part) {
			$pair = explode('=', trim($part), 2);

			if (count($pair) !== 2) {
				continue;
			}
			if ($pair[0] === 't' && ctype_digit($pair[1])) {
				$timestamp = (int) $pair[1];
			}
			if ($pair[0] === self::VERSION) {
				$digest = $pair[1];
			}
		}

		return $timestamp === null || $digest === null ? null : [$timestamp, $digest];
	}
}
