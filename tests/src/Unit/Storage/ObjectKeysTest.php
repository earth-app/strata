<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Storage;

use Drupal\strata\Storage\ObjectKeys;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Proves every provider prefixes and validates a key the same way.
 *
 * A key arrives from a manifest, and a manifest is a file in a bucket somebody else may be able to
 * write. Refusing a traversal here is what keeps a hostile manifest from addressing an object
 * outside the store, and doing it in one place is what keeps three providers from disagreeing about
 * which keys are legal.
 */
#[CoversClass(ObjectKeys::class)]
class ObjectKeysTest extends TestCase
{
	#[Test]
	#[TestDox('a prefix is normalised to exactly one trailing slash and no leading one')]
	#[Group('strata/storage')]
	public function prefixesAreNormalised(): void
	{
		$this->assertSame('_strata/site-1/', (new ObjectKeys('/_strata/site-1'))->prefix);
		$this->assertSame('_strata/site-1/', (new ObjectKeys('_strata/site-1//'))->prefix);
		$this->assertSame('', (new ObjectKeys(''))->prefix);
		$this->assertSame('', (new ObjectKeys('///'))->prefix);
	}

	#[Test]
	#[TestDox('a key is prefixed on the way out and stripped on the way back')]
	#[Group('strata/storage')]
	public function keysRoundTripThroughThePrefix(): void
	{
		$keys = new ObjectKeys('_strata/site-1');

		$this->assertSame('_strata/site-1/frames/ab/cd', $keys->resolve('frames/ab/cd'));
		$this->assertSame('frames/ab/cd', $keys->strip($keys->resolve('frames/ab/cd')));
	}

	#[Test]
	#[TestDox('a leading slash is stripped rather than treated as an absolute key')]
	#[Group('strata/storage')]
	public function leadingSlashesAreStripped(): void
	{
		$keys = new ObjectKeys('_strata/site-1');

		$this->assertSame('_strata/site-1/frames/thing', $keys->resolve('/frames/thing/'));
		$this->assertSame('frames/thing', $keys->normalize('/frames/thing/'));
	}

	#[Test]
	#[TestDox('a key that never carried the prefix is left alone by strip')]
	#[Group('strata/storage')]
	public function strippingAnUnprefixedKeyChangesNothing(): void
	{
		$this->assertSame(
			'somebody-elses/object',
			(new ObjectKeys('_strata/site-1'))->strip('somebody-elses/object'),
		);
		$this->assertSame('frames/thing', (new ObjectKeys(''))->strip('frames/thing'));
	}

	#[Test]
	#[TestDox('a listing prefix may be empty, which a key may not')]
	#[Group('strata/storage')]
	public function listingPrefixesMayBeEmpty(): void
	{
		$keys = new ObjectKeys('_strata/site-1');

		$this->assertSame('_strata/site-1/', $keys->scope(''));
		$this->assertSame('_strata/site-1/frames/', $keys->scope('/frames/'));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function unsafeKeyProvider(): array
	{
		return [
			'parent traversal' => ['../escape'],
			'interior traversal' => ['frames/../../escape'],
			'current directory' => ['frames/./thing'],
			'empty segment' => ['frames//thing'],
			'empty key' => [''],
			'only slashes' => ['///'],
			'a null byte' => ["frames/th\0ing"],
			'the reserved suffix' => ['frames/thing.tmp'],
		];
	}

	#[Test]
	#[TestDox('a key containing $_dataName is refused rather than normalised away')]
	#[Group('strata/storage')]
	#[DataProvider('unsafeKeyProvider')]
	public function unsafeKeysAreRefused(string $key): void
	{
		$this->expectException(InvalidArgumentException::class);

		(new ObjectKeys('_strata/site-1'))->resolve($key);
	}

	#[Test]
	#[TestDox('a dot inside a segment is legal, because an object key may contain one')]
	#[Group('strata/storage')]
	public function dotsInsideSegmentsAreLegal(): void
	{
		$keys = new ObjectKeys('_strata/site-1');

		$this->assertSame('_strata/site-1/packs/01.pack', $keys->resolve('packs/01.pack'));
		$this->assertSame('_strata/site-1/frames/..hidden', $keys->resolve('frames/..hidden'));
	}
}
