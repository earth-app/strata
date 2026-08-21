<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Capture;

use Drupal\strata\Capture\Classifier\Classification;
use Drupal\strata\Capture\Classifier\ClassificationProfile;
use Drupal\strata\Capture\Classifier\DiscoveryReport;
use Drupal\strata\Capture\Classifier\Heuristics;
use Drupal\strata\Health\Tripwire\UnclassifiedGrowth;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Classification::class)]
#[CoversClass(Heuristics::class)]
#[CoversClass(ClassificationProfile::class)]
#[CoversClass(DiscoveryReport::class)]
#[CoversClass(UnclassifiedGrowth::class)]
class ClassificationTest extends TestCase
{
	#region The Three States

	#[Test]
	#[TestDox('only authoritative keys are restored, and only derivable keys are skipped')]
	#[Group('strata/capture')]
	public function whatEachStateMeans(): void
	{
		$this->assertTrue(Classification::AUTHORITATIVE->isCaptured());
		$this->assertTrue(Classification::AUTHORITATIVE->isRestored());

		$this->assertFalse(Classification::DERIVABLE->isCaptured());
		$this->assertFalse(Classification::DERIVABLE->isRestored());

		// captured but not restored: stored so nothing is lost, not written back on a guess
		$this->assertTrue(Classification::UNCLASSIFIED->isCaptured());
		$this->assertFalse(Classification::UNCLASSIFIED->isRestored());
	}

	#[Test]
	#[TestDox('only the unclassified state needs a human')]
	#[Group('strata/capture')]
	public function onlyUnclassifiedNeedsADecision(): void
	{
		$this->assertTrue(Classification::UNCLASSIFIED->needsDecision());
		$this->assertFalse(Classification::AUTHORITATIVE->needsDecision());
		$this->assertFalse(Classification::DERIVABLE->needsDecision());
	}

	#[Test]
	#[TestDox('every state has a label for the admin UI')]
	#[Group('strata/capture')]
	public function everyStateIsLabelled(): void
	{
		foreach (Classification::cases() as $case) {
			$this->assertNotSame('', $case->label());
		}
	}

	#endregion

	#region Heuristics

	/**
	 * @return array<string, array{string, Classification}>
	 */
	public static function keyProvider(): array
	{
		return [
			'a queue' => ['queue:aggregator_feeds', Classification::AUTHORITATIVE],
			'a reliable queue' => ['reliable_queue:cron', Classification::AUTHORITATIVE],
			'a flood counter' => ['flood:user.failed_login_ip', Classification::AUTHORITATIVE],
			'a session' => ['sessions:abc123', Classification::AUTHORITATIVE],
			'a semaphore' => ['semaphore:cron', Classification::AUTHORITATIVE],
			'a key-value collection' => ['key_value:system.schema', Classification::AUTHORITATIVE],
			'a lock' => ['lock:cron', Classification::DERIVABLE],
			'the render cache' => ['cache_render:foo', Classification::DERIVABLE],
			'the page cache' => ['page_cache:bar', Classification::DERIVABLE],
			'cache tags' => ['cachetags:node:1', Classification::DERIVABLE],
			'the config cache' => ['config:system.site', Classification::DERIVABLE],
			'the discovery cache' => ['discovery:plugins', Classification::DERIVABLE],
			'the entity cache' => ['entity:node:1', Classification::DERIVABLE],
			'an expirable store' => ['key_value_expire:tempstore', Classification::DERIVABLE],
			'a module nobody has heard of' => ['acme_widgets:thing', Classification::UNCLASSIFIED],
			'an empty key' => ['', Classification::UNCLASSIFIED],
		];
	}

	#[Test]
	#[TestDox('$_dataName is classified by name')]
	#[Group('strata/capture')]
	#[DataProvider('keyProvider')]
	public function classification(string $key, Classification $expected): void
	{
		$this->assertSame($expected, Heuristics::classify($key));
	}

	#[Test]
	#[TestDox('classification does not depend on how the key was written')]
	#[Group('strata/capture')]
	public function classificationIsCaseInsensitive(): void
	{
		$this->assertSame(Classification::DERIVABLE, Heuristics::classify('  CACHE_RENDER:Foo  '));
	}

	#[Test]
	#[TestDox('a matched key reports the pattern that matched, so a registry stores one row')]
	#[Group('strata/capture')]
	public function patternForMatchedKey(): void
	{
		$this->assertSame('cache*', Heuristics::patternFor('cache_render:foo'));
		$this->assertSame('queue*', Heuristics::patternFor('queue:cron'));
	}

	#[Test]
	#[TestDox('an unmatched key reports its own namespace, so its rows still collapse')]
	#[Group('strata/capture')]
	public function patternForUnmatchedKey(): void
	{
		$this->assertSame('acme_widgets*', Heuristics::patternFor('acme_widgets:thing'));
		$this->assertSame('acme_widgets*', Heuristics::patternFor('acme_widgets:other'));
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function namespaceProvider(): array
	{
		return [
			'a colon separator' => ['queue:cron', 'queue'],
			'a dot separator' => ['system.schema', 'system'],
			'no separator' => ['watchdog', 'watchdog'],
			'an underscore is part of the name' => ['cache_render:foo', 'cache_render'],
			'several separators' => ['a:b:c', 'a'],
		];
	}

	#[Test]
	#[TestDox('$_dataName resolves to the right namespace')]
	#[Group('strata/capture')]
	#[DataProvider('namespaceProvider')]
	public function namespaces(string $key, string $expected): void
	{
		$this->assertSame($expected, Heuristics::namespaceOf($key));
	}

	#[Test]
	#[TestDox('matching uses a portable glob rather than fnmatch')]
	#[Group('strata/capture')]
	public function matching(): void
	{
		$this->assertTrue(Heuristics::matches('cache*', 'cache_render'));
		$this->assertTrue(Heuristics::matches('*_cache', 'page_cache'));
		$this->assertTrue(Heuristics::matches('*expirable*', 'my_expirable_store'));
		$this->assertTrue(Heuristics::matches('exact', 'exact'));
		$this->assertFalse(Heuristics::matches('cache*', 'nocache'));
		$this->assertFalse(Heuristics::matches('exact', 'exactly'));

		// a regex metacharacter in a key is a literal, not a pattern
		$this->assertFalse(Heuristics::matches('a.c', 'abc'));
		$this->assertTrue(Heuristics::matches('a.c', 'a.c'));
	}

	#[Test]
	#[TestDox('every shipped rule resolves to a decided state, never to unclassified')]
	#[Group('strata/capture')]
	public function everyRuleDecides(): void
	{
		foreach (Heuristics::RULES as $pattern => $classification) {
			$this->assertFalse(
				$classification->needsDecision(),
				sprintf('rule %s decides something', $pattern),
			);
		}
	}

	#endregion

	#region Profiles

	#[Test]
	#[TestDox('a profile round trips through its serialized form')]
	#[Group('strata/capture')]
	public function profileRoundTrips(): void
	{
		$profile = new ClassificationProfile(
			['acme*' => Classification::AUTHORITATIVE, 'noise*' => Classification::DERIVABLE],
			'earth-app production',
		);

		$restored = ClassificationProfile::fromArray($profile->jsonSerialize());

		$this->assertSame(2, $restored->count());
		$this->assertSame('earth-app production', $restored->label);
		$this->assertSame(Classification::AUTHORITATIVE, $restored->decisions['acme*']);
		$this->assertSame(Classification::DERIVABLE, $restored->decisions['noise*']);
	}

	#[Test]
	#[TestDox('a profile from a later version is refused rather than half read')]
	#[Group('strata/capture')]
	public function laterVersionIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('not version 9');

		ClassificationProfile::fromArray(['version' => 9, 'decisions' => []]);
	}

	#[Test]
	#[TestDox('a classification this release does not know is skipped, not guessed at')]
	#[Group('strata/capture')]
	public function unknownClassificationIsSkipped(): void
	{
		$profile = ClassificationProfile::fromArray([
			'version' => ClassificationProfile::VERSION,
			'decisions' => ['acme*' => 'authoritative', 'future*' => 'something_new'],
		]);

		$this->assertSame(1, $profile->count());
		$this->assertArrayNotHasKey('future*', $profile->decisions);
	}

	#[Test]
	#[TestDox('a profile with an empty pattern is refused')]
	#[Group('strata/capture')]
	public function emptyPatternIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('empty pattern');

		new ClassificationProfile(['  ' => Classification::DERIVABLE]);
	}

	#[Test]
	#[TestDox('a file that is not a profile is refused by name')]
	#[Group('strata/capture')]
	public function unreadableFileIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot read');

		ClassificationProfile::fromFile('/nonexistent/strata/profile.json');
	}

	#endregion

	#region Reports

	#[Test]
	#[TestDox('a pass with no sources says it could not look, rather than reporting a clean sweep')]
	#[Group('strata/capture')]
	public function noSourcesIsNotClean(): void
	{
		$report = new DiscoveryReport();

		$this->assertFalse($report->couldLook());
		$this->assertStringContainsString('no keyspace source is registered', $report->summary());
	}

	#[Test]
	#[TestDox('a report counts patterns, keys and bytes per classification')]
	#[Group('strata/capture')]
	public function reportTotals(): void
	{
		$report = new DiscoveryReport(2, 1500, 12, [
			'authoritative' => ['patterns' => 3, 'keys' => 100, 'bytes' => 4096],
			'derivable' => ['patterns' => 8, 'keys' => 1390, 'bytes' => 900000],
			'unclassified' => ['patterns' => 1, 'keys' => 10, 'bytes' => 512],
		]);

		$this->assertTrue($report->couldLook());
		$this->assertTrue($report->isClean());
		$this->assertSame(3, $report->patternsAt(Classification::AUTHORITATIVE));
		$this->assertSame(1390, $report->keysAt(Classification::DERIVABLE));
		$this->assertSame(512, $report->bytesAt(Classification::UNCLASSIFIED));
		$this->assertStringContainsString('1 undecided', $report->summary());
	}

	#[Test]
	#[TestDox('a source that could not be read is named in the summary')]
	#[Group('strata/capture')]
	public function unreadableSourceIsReported(): void
	{
		$report = new DiscoveryReport(2, 10, 2, [], ['redis: connection refused']);

		$this->assertFalse($report->isClean());
		$this->assertStringContainsString('1 sources unreadable', $report->summary());
	}

	#endregion

	#region Growth

	#[Test]
	#[TestDox('an undecided share past a quarter of the keyspace raises a warning')]
	#[Group('strata/capture')]
	public function growthFires(): void
	{
		$finding = (new UnclassifiedGrowth())->check([
			'total_keys' => 1000,
			'unclassified_keys' => 400,
			'unclassified_patterns' => 3,
		]);

		$this->assertNotNull($finding);
		$this->assertSame('classification.unclassified_growth', $finding->code);
		$this->assertStringContainsString('40.0%', $finding->context);
		$this->assertStringContainsString('3 patterns', $finding->context);
	}

	#[Test]
	#[TestDox('an undecided share within the allowance is quiet')]
	#[Group('strata/capture')]
	public function smallShareIsQuiet(): void
	{
		$this->assertNull(
			(new UnclassifiedGrowth())->check([
				'total_keys' => 1000,
				'unclassified_keys' => 100,
				'unclassified_patterns' => 1,
			]),
		);
	}

	#[Test]
	#[TestDox('a keyspace too small to mean anything is quiet whatever the share')]
	#[Group('strata/capture')]
	public function tinyKeyspaceIsQuiet(): void
	{
		$this->assertNull(
			(new UnclassifiedGrowth())->check([
				'total_keys' => 10,
				'unclassified_keys' => 10,
				'unclassified_patterns' => 2,
			]),
		);
	}

	#[Test]
	#[TestDox('a fully classified keyspace is quiet')]
	#[Group('strata/capture')]
	public function nothingUndecidedIsQuiet(): void
	{
		$this->assertNull(
			(new UnclassifiedGrowth())->check([
				'total_keys' => 5000,
				'unclassified_keys' => 0,
				'unclassified_patterns' => 0,
			]),
		);
	}

	#endregion
}
