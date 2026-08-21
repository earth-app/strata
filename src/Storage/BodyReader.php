<?php

declare(strict_types=1);

namespace Drupal\strata\Storage;

use Generator;
use RuntimeException;

/**
 * Turns a put body into the pieces one endpoint takes.
 *
 * Every remote provider faces the same three problems and none of them is vendor-specific: a body
 * arrives as a string or as a stream, a stream may not be able to say how long it is, and a body
 * too large for one request has to be cut into uniform pieces with a short one at the end. The
 * vendor decides what a piece is called and how large it may be; cutting it is arithmetic.
 *
 * A short read is never silently accepted. A read that fails mid-stream raises naming the key,
 * because a provider that returned what it had would write a truncated object that no reader can
 * tell from a real one.
 *
 * @see StorageProviderInterface
 */
final class BodyReader
{
	/**
	 * Bytes read per iteration when a body arrives as a stream.
	 */
	public const COPY_CHUNK = 1_048_576;

	/**
	 * Cuts a string into uniform pieces.
	 *
	 * @param string $body
	 *   The bytes.
	 * @param int $partSize
	 *   Bytes per piece.
	 *
	 * @return Generator<int, string, mixed, void>
	 *   Every piece the same size except the last.
	 */
	public static function sliceString(string $body, int $partSize): Generator
	{
		foreach (str_split($body, max(1, $partSize)) as $part) {
			yield $part;
		}
	}

	/**
	 * Cuts a stream into uniform pieces.
	 *
	 * @param resource $stream
	 *   An open readable stream, positioned after $first.
	 * @param int $partSize
	 *   Bytes per piece.
	 * @param string $first
	 *   The piece already read in order to decide that one request would not carry the body.
	 * @param string $key
	 *   Object key, for error messages.
	 *
	 * @return Generator<int, string, mixed, void>
	 *   Every piece the same size except the last. A body ending exactly on a boundary yields no
	 *   empty final piece.
	 *
	 * @throws RuntimeException
	 *   When the stream fails mid-read.
	 */
	public static function sliceStream(
		$stream,
		int $partSize,
		string $first,
		string $key,
	): Generator {
		yield $first;

		while (strlen($first) === $partSize) {
			$first = self::readExactly($stream, $partSize, $key);

			if ($first === '') {
				return;
			}

			yield $first;
		}
	}

	/**
	 * Reads a stream to the end.
	 *
	 * @param resource $stream
	 *   An open readable stream.
	 * @param string $key
	 *   Object key, for error messages.
	 *
	 * @return string
	 *   Everything from the current position to EOF.
	 *
	 * @throws RuntimeException
	 *   When a read fails.
	 */
	public static function readAll($stream, string $key): string
	{
		$buffer = '';

		while (!feof($stream)) {
			$block = fread($stream, self::COPY_CHUNK);

			if ($block === false) {
				throw new RuntimeException(sprintf('Failed reading the body for %s', $key));
			}
			if ($block === '') {
				break;
			}

			$buffer .= $block;
		}

		return $buffer;
	}

	/**
	 * Reads a fixed number of bytes, or fewer at the end of the stream.
	 *
	 * @param resource $stream
	 *   An open readable stream.
	 * @param int $want
	 *   Bytes to read.
	 * @param string $key
	 *   Object key, for error messages.
	 *
	 * @return string
	 *   Exactly $want bytes, or fewer when the stream ended.
	 *
	 * @throws RuntimeException
	 *   When a read fails.
	 */
	public static function readExactly($stream, int $want, string $key): string
	{
		$buffer = '';

		while (strlen($buffer) < $want) {
			$block = fread($stream, $want - strlen($buffer));

			if ($block === false) {
				throw new RuntimeException(sprintf('Failed reading the body for %s', $key));
			}
			if ($block === '') {
				break;
			}

			$buffer .= $block;
		}

		return $buffer;
	}

	/**
	 * How many bytes a stream has left, where it can say.
	 *
	 * @param resource $stream
	 *   An open stream.
	 *
	 * @return int|null
	 *   Bytes from the current position to the end, or NULL for a pipe or any other stream that
	 *   cannot report a size.
	 */
	public static function sizeOf($stream): ?int
	{
		$stat = @fstat($stream);
		$position = ftell($stream);

		if (!is_array($stat) || !isset($stat['size']) || $position === false) {
			return null;
		}

		$size = (int) $stat['size'];

		return $size <= 0 ? null : max(0, $size - $position);
	}
}
