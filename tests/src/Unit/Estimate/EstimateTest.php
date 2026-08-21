<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Unit\Estimate;

use Drupal\strata\Estimate\CollapseModel;
use Drupal\strata\Estimate\Estimator;
use Drupal\strata\Estimate\FreeTierModel;
use Drupal\strata\Estimate\Measurement;
use Drupal\strata\Estimate\PriceTable;
use Drupal\strata\Estimate\Projection;
use Drupal\strata\Journal\Realm;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(Estimator::class)]
#[CoversClass(Measurement::class)]
#[CoversClass(Projection::class)]
#[CoversClass(PriceTable::class)]
#[CoversClass(CollapseModel::class)]
#[CoversClass(FreeTierModel::class)]
class EstimateTest extends TestCase
{
	/**
	 * The site the recorded cost model was worked out against.
	 *
	 * 50,000 users, 200,000 nodes and 12,000 custom rows, which is 262,000 subjects writing 82,892
	 * operations a day. Both of those are figures the model produced when it was first derived, so a
	 * change that moves either of them has changed the model rather than the code.
	 */
	private function referenceSite(): Measurement
	{
		return new Measurement(users: 50_000, nodes: 200_000, rows: 12_000, baseInterval: 3600);
	}

	#region Reference Model

	#[Test]
	#[TestDox('the reference site has the subject count and write rate the model was derived from')]
	#[Group('strata/estimate')]
	public function referenceShapeIsUnchanged(): void
	{
		$site = $this->referenceSite();
		$ops = (new Estimator())->operationsPerDay($site);

		$this->assertSame(262_000, $site->subjects());
		$this->assertSame(82_892, (int) round(array_sum($ops)));
	}

	#[Test]
	#[TestDox('two thirds of the write volume is user access timestamp churn')]
	#[Group('strata/estimate')]
	public function accessChurnDominatesByCount(): void
	{
		$estimator = new Estimator();
		$site = $this->referenceSite();
		$total = array_sum($estimator->operationsPerDay($site));
		$access = 0.0;

		foreach ($estimator->populations($site) as $population) {
			if ($population['name'] === 'access_churn') {
				$access = $population['ops'];
			}
		}

		$this->assertGreaterThan(0.6, $access / $total);
		$this->assertLessThan(0.75, $access / $total);
	}

	#[Test]
	#[TestDox('the tree is what a large mostly-idle site pays for')]
	#[Group('strata/estimate')]
	public function treeDominatesALargeSite(): void
	{
		$projection = (new Estimator())->project($this->referenceSite());

		$this->assertSame('tree', $projection->dominantComponent());
		$this->assertGreaterThan(0.5, $projection->share('tree'));
	}

	#[Test]
	#[TestDox('the base manifests come out at the measured figure the constant was fitted to')]
	#[Group('strata/estimate')]
	public function treeMatchesItsCalibrationPoint(): void
	{
		$projection = (new Estimator())->project($this->referenceSite());
		$gigabytes = $projection->treeBytes / PriceTable::BYTES_PER_GB;

		// 7.23 GB a year of manifests at 262,000 subjects with hourly bases
		$this->assertGreaterThan(7.0, $gigabytes);
		$this->assertLessThan(7.5, $gigabytes);
	}

	#endregion

	#region Dials

	#[Test]
	#[TestDox('halving the flush interval doubles the requests and leaves the bytes alone')]
	#[Group('strata/estimate')]
	public function intervalMovesRequestsNotBytes(): void
	{
		$estimator = new Estimator();
		$site = $this->referenceSite();

		$slow = $estimator->project($site->with(['segment_interval' => 60]));
		$fast = $estimator->project($site->with(['segment_interval' => 30]));

		$this->assertSame($slow->totalBytes(), $fast->totalBytes());
		$this->assertSame($slow->writesPerMonth * 2, $fast->writesPerMonth);
	}

	#[Test]
	#[TestDox('the request count does not depend on how big the site is')]
	#[Group('strata/estimate')]
	public function requestsDoNotScaleWithSize(): void
	{
		$estimator = new Estimator();
		$small = $estimator->project(new Measurement(users: 1000, nodes: 5000));
		$large = $estimator->project(new Measurement(users: 500_000, nodes: 2_000_000));

		$this->assertSame($small->writesPerMonth, $large->writesPerMonth);
		$this->assertGreaterThan($small->totalBytes(), $large->totalBytes());
	}

	#[Test]
	#[TestDox('lengthening the base interval barely moves the stored bytes')]
	#[Group('strata/estimate')]
	public function baseIntervalIsAWeakLever(): void
	{
		$estimator = new Estimator();
		$site = $this->referenceSite();

		$hourly = $estimator->project($site->with(['base_interval' => 3600]));
		$daily = $estimator->project($site->with(['base_interval' => 86_400]));

		// a longer window means fewer bases, each recording more changed subjects
		$this->assertGreaterThan(0.6, $daily->treeBytes / $hourly->treeBytes);
		$this->assertLessThan(1.0, $daily->treeBytes / $hourly->treeBytes);
	}

	#[Test]
	#[TestDox('retention scales the stored operations in proportion')]
	#[Group('strata/estimate')]
	public function retentionScalesJournalBytes(): void
	{
		$estimator = new Estimator();
		$site = $this->referenceSite();

		$year = $estimator->project($site->with(['retention_days' => 365]));
		$month = $estimator->project($site->with(['retention_days' => 30]));

		$ratio = $year->journalBytes() / max(1, $month->journalBytes());

		$this->assertGreaterThan(11.9, $ratio);
		$this->assertLessThan(12.3, $ratio);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function accessModeProvider(): array
	{
		return [
			'an event costs less than a delta' => [
				Measurement::ACCESS_EVENT,
				Measurement::ACCESS_DELTA,
			],
			'nothing costs less than an event, before the reconciler picks the row up' => [
				Measurement::ACCESS_OFF,
				Measurement::ACCESS_EVENT,
			],
		];
	}

	#[Test]
	#[TestDox('$_dataName')]
	#[Group('strata/estimate')]
	#[DataProvider('accessModeProvider')]
	public function accessModesAreOrdered(string $cheaper, string $dearer): void
	{
		$estimator = new Estimator();
		$site = $this->referenceSite();

		$this->assertLessThan(
			$estimator->project($site->with(['access_churn' => $dearer]))->journalBytes(),
			$estimator->project($site->with(['access_churn' => $cheaper]))->journalBytes(),
		);
	}

	#[Test]
	#[TestDox('switching access churn off is called out, since it costs more than it saves')]
	#[Group('strata/estimate')]
	public function accessOffIsCalledOut(): void
	{
		$projection = (new Estimator())->project(
			$this->referenceSite()->with(['access_churn' => Measurement::ACCESS_OFF]),
		);

		$this->assertNotSame([], $projection->notes);
		$this->assertStringContainsString('reconciler', implode(' ', $projection->notes));
	}

	#[Test]
	#[TestDox('files are most of the bytes once they are captured, and nothing before')]
	#[Group('strata/estimate')]
	public function filesDominateWhenCaptured(): void
	{
		$estimator = new Estimator();
		$site = $this->referenceSite()->with(['files' => 100_000, 'file_bytes' => 80_000_000_000]);

		$without = $estimator->project($site);
		$with = $estimator->project($site->with(['capture_files' => true]));

		$this->assertSame(0, $without->fileBytes);
		$this->assertSame('files', $with->dominantComponent());
		$this->assertGreaterThan(0.9, $with->share('files'));
	}

	#endregion

	#region Calibration

	#[Test]
	#[TestDox('supplied operation counts replace the derived rates, which is what a back-test is')]
	#[Group('strata/estimate')]
	public function suppliedCountsWin(): void
	{
		$estimator = new Estimator();
		$measured = new Measurement(
			users: 50_000,
			nodes: 200_000,
			opsPerDay: [Realm::ENTITY->value => 100.0, Realm::CONFIG->value => 4.0],
		);

		$ops = $estimator->operationsPerDay($measured);

		$this->assertSame(104.0, array_sum($ops));
		$this->assertSame([Realm::ENTITY->value, Realm::CONFIG->value], array_keys($ops));
	}

	#[Test]
	#[TestDox('an operation size measured on this host replaces the reference one')]
	#[Group('strata/estimate')]
	public function suppliedOpSizesWin(): void
	{
		$site = $this->referenceSite();
		$reference = (new Estimator())->project($site);
		$calibrated = (new Estimator(['access_event' => 480]))->project($site);

		$this->assertGreaterThan($reference->journalBytes(), $calibrated->journalBytes());
	}

	#[Test]
	#[TestDox('the compression ratio comes from the codec catalog rather than a second copy of it')]
	#[Group('strata/estimate')]
	public function ratioComesFromTheCatalog(): void
	{
		$this->assertSame(Estimator::COMPACTION_RATIO, (new Estimator())->ratio());
		$this->assertSame(3.0, (new Estimator([], 3.0))->ratio());
		$this->assertSame(
			1.0,
			(new Estimator([], 0.5))->ratio(),
			'a ratio below one would add bytes',
		);
	}

	#endregion

	#region Collapse

	#[Test]
	#[TestDox('a window nothing repeats in collapses not at all')]
	#[Group('strata/estimate')]
	public function collapseIsOneBelowSaturation(): void
	{
		$this->assertSame(1.0, CollapseModel::factor(0, 1000));
		$this->assertSame(1.0, CollapseModel::factor(1, 1000));
		$this->assertLessThan(1.01, CollapseModel::factor(10, 1_000_000));
	}

	#[Test]
	#[TestDox('a window that writes every subject many times collapses by that many')]
	#[Group('strata/estimate')]
	public function collapseRisesWithRepeats(): void
	{
		$this->assertGreaterThan(9.0, CollapseModel::factor(100_000, 10_000));
		$this->assertSame(5.0, CollapseModel::factor(5, 1));
	}

	#[Test]
	#[TestDox('the distinct count reproduces the derived hourly churn figure')]
	#[Group('strata/estimate')]
	public function distinctReproducesTheDerivedChurn(): void
	{
		// 3,454 writes an hour over 262,000 subjects touches 3,429 of them, or 1.31% of the tree
		$distinct = CollapseModel::distinct(3454, 262_000);

		$this->assertGreaterThan(3420.0, $distinct);
		$this->assertLessThan(3440.0, $distinct);
		$this->assertGreaterThan(0.013, $distinct / 262_000);
		$this->assertLessThan(0.0132, $distinct / 262_000);
	}

	#[Test]
	#[TestDox('a population that does not collapse holds the weighted factor down')]
	#[Group('strata/estimate')]
	public function weightedFactorFollowsTheBytes(): void
	{
		$this->assertSame(1.0, CollapseModel::weighted([]));
		$this->assertSame(10.0, CollapseModel::weighted([['bytes' => 100.0, 'factor' => 10.0]]));
		$this->assertLessThan(
			1.1,
			CollapseModel::weighted([
				['bytes' => 1000.0, 'factor' => 1.0],
				['bytes' => 10.0, 'factor' => 100.0],
			]),
			'the large population that does not collapse decides the total',
		);
	}

	#[Test]
	#[TestDox('a write count too large to raise to a power still resolves')]
	#[Group('strata/estimate')]
	public function hugeWriteCountsDoNotUnderflow(): void
	{
		$distinct = CollapseModel::distinct(1_000_000_000, 1000);

		$this->assertGreaterThan(999.0, $distinct);
		$this->assertLessThanOrEqual(1000.0, $distinct);
	}

	#endregion

	#region Prices

	#[Test]
	#[TestDox('a free allowance is subtracted before anything is charged')]
	#[Group('strata/estimate')]
	public function freeAllowanceComesOffFirst(): void
	{
		$r2 = PriceTable::r2();

		$this->assertSame(0.0, $r2->storageCost(5 * PriceTable::BYTES_PER_GB));
		$this->assertSame(0.0, $r2->writeCost(500_000));
		$this->assertEqualsWithDelta(
			0.015,
			$r2->storageCost(11 * PriceTable::BYTES_PER_GB),
			1.0e-9,
		);
		$this->assertEqualsWithDelta(4.5, $r2->writeCost(2_000_000), 1.0e-9);
	}

	#[Test]
	#[TestDox('s3 charges from the first byte and the first request')]
	#[Group('strata/estimate')]
	public function s3ChargesImmediately(): void
	{
		$s3 = PriceTable::s3();

		$this->assertFalse($s3->hasFreeTier());
		$this->assertEqualsWithDelta(0.023, $s3->storageCost(PriceTable::BYTES_PER_GB), 1.0e-9);
		$this->assertEqualsWithDelta(5.0, $s3->writeCost(1_000_000), 1.0e-9);
		$this->assertEqualsWithDelta(0.09, $s3->egressCost(PriceTable::BYTES_PER_GB), 1.0e-9);
	}

	#[Test]
	#[TestDox('a restore costs nothing to pull on r2 and something on s3')]
	#[Group('strata/estimate')]
	public function egressIsFreeOnR2(): void
	{
		$bytes = 100 * PriceTable::BYTES_PER_GB;

		$this->assertSame(0.0, PriceTable::r2()->egressCost($bytes));
		$this->assertGreaterThan(0.0, PriceTable::s3()->egressCost($bytes));
	}

	#[Test]
	#[TestDox('a local store is priced at zero rather than left out')]
	#[Group('strata/estimate')]
	public function localIsFree(): void
	{
		$local = PriceTable::local();

		$this->assertSame(0.0, $local->monthlyCost(1_000_000_000_000, 10_000_000));
		$this->assertFalse($local->hasFreeTier());
	}

	#[Test]
	#[TestDox('every shipped table is reachable by its id')]
	#[Group('strata/estimate')]
	public function tablesAreKeyedById(): void
	{
		foreach (PriceTable::all() as $id => $table) {
			$this->assertSame($id, $table->id);
			$this->assertSame($id, PriceTable::of($id)->id);
		}
	}

	#[Test]
	#[TestDox('an unknown provider is refused rather than priced at zero')]
	#[Group('strata/estimate')]
	public function unknownProviderIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('No price table is shipped');

		PriceTable::of('azure');
	}

	#[Test]
	#[TestDox('a negative price is refused')]
	#[Group('strata/estimate')]
	public function negativePriceIsRefused(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A price cannot be negative');

		new PriceTable('bad', 'Bad', -1.0, 1.0, 1.0);
	}

	#endregion

	#region Free Tier

	#[Test]
	#[TestDox('a small site on r2 costs nothing and says which allowance is closest')]
	#[Group('strata/estimate')]
	public function smallSiteIsFreeOnR2(): void
	{
		$projection = (new Estimator())->project(new Measurement(users: 1000, nodes: 5000));
		$verdict = new FreeTierModel(PriceTable::r2(), $projection);

		$this->assertTrue($verdict->fits());
		$this->assertLessThan(1.0, $verdict->usage(FreeTierModel::STORAGE));
		$this->assertGreaterThan(0, $verdict->headroom(FreeTierModel::STORAGE));
		$this->assertContains($verdict->bindingAxis(), [
			FreeTierModel::STORAGE,
			FreeTierModel::WRITES,
		]);
	}

	#[Test]
	#[TestDox('a site past the allowance does not fit and reports usage above one')]
	#[Group('strata/estimate')]
	public function largeSiteDoesNotFit(): void
	{
		$projection = (new Estimator())->project(
			new Measurement(users: 5_000_000, nodes: 20_000_000),
		);
		$verdict = new FreeTierModel(PriceTable::r2(), $projection);

		$this->assertFalse($verdict->fits());
		$this->assertGreaterThan(1.0, $verdict->usage(FreeTierModel::STORAGE));
		$this->assertSame(0, $verdict->headroom(FreeTierModel::STORAGE));
	}

	#[Test]
	#[TestDox('a provider with no allowance never fits and names no binding axis')]
	#[Group('strata/estimate')]
	public function noFreeTierNeverFits(): void
	{
		$projection = (new Estimator())->project(new Measurement(users: 1));
		$verdict = new FreeTierModel(PriceTable::s3(), $projection);

		$this->assertFalse($verdict->fits());
		$this->assertSame('', $verdict->bindingAxis());
		$this->assertSame(0.0, $verdict->usage(FreeTierModel::STORAGE));
	}

	#[Test]
	#[TestDox('the capacity search finds a site that fits and rejects the next one up')]
	#[Group('strata/estimate')]
	public function capacityIsTheLargestSiteThatFits(): void
	{
		$estimator = new Estimator();
		$shape = $this->referenceSite();
		$prices = PriceTable::r2();
		$users = FreeTierModel::capacity($estimator, $shape, $prices);

		$this->assertGreaterThan(0, $users);

		$fits = static fn(int $count): bool => (new FreeTierModel(
			$prices,
			$estimator->project(
				$shape->with([
					'users' => $count,
					'nodes' => (int) round($count * ($shape->nodes / $shape->users)),
					'rows' => (int) round($count * ($shape->rows / $shape->users)),
				]),
			),
		))->fits();

		$this->assertTrue($fits($users));
		$this->assertFalse($fits((int) round($users * 1.5)));
	}

	#[Test]
	#[TestDox('a provider with no allowance has no capacity to search for')]
	#[Group('strata/estimate')]
	public function noFreeTierHasNoCapacity(): void
	{
		$this->assertSame(
			0,
			FreeTierModel::capacity(new Estimator(), $this->referenceSite(), PriceTable::s3()),
		);
	}

	#endregion

	#region Measurement

	/**
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function badMeasurementProvider(): array
	{
		return [
			'a negative user count' => [['users' => -1], 'below zero'],
			'an active share above one' => [['active_share' => 1.5], 'fraction between'],
			'a zero flush interval' => [['segment_interval' => 0], 'at least one'],
			'a zero retention' => [['retention_days' => 0], 'at least one'],
			'an unknown access mode' => [['access_churn' => 'sometimes'], 'Unknown access churn'],
		];
	}

	#[Test]
	#[TestDox('$_dataName is refused')]
	#[Group('strata/estimate')]
	#[DataProvider('badMeasurementProvider')]
	public function badMeasurementsAreRefused(array $values, string $message): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		Measurement::fromArray($values + ['users' => 10]);
	}

	#[Test]
	#[TestDox('a measurement round trips through its serialized form')]
	#[Group('strata/estimate')]
	public function measurementRoundTrips(): void
	{
		$site = $this->referenceSite();

		$this->assertSame(
			$site->jsonSerialize(),
			Measurement::fromArray($site->jsonSerialize())->jsonSerialize(),
		);
	}

	#[Test]
	#[TestDox('varying one field leaves the rest of the shape alone')]
	#[Group('strata/estimate')]
	public function withVariesOneField(): void
	{
		$site = $this->referenceSite();
		$varied = $site->with(['users' => 1]);

		$this->assertSame(1, $varied->users);
		$this->assertSame($site->nodes, $varied->nodes);
		$this->assertSame($site->baseInterval, $varied->baseInterval);
	}

	#[Test]
	#[TestDox('varying a field that does not exist is refused rather than ignored')]
	#[Group('strata/estimate')]
	public function withRefusesUnknownFields(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unknown measurement field');

		$this->referenceSite()->with(['user' => 1]);
	}

	#[Test]
	#[TestDox('a projection serializes to something a form and a command can both render')]
	#[Group('strata/estimate')]
	public function projectionSerializes(): void
	{
		$data = (new Estimator())->project($this->referenceSite())->jsonSerialize();

		$this->assertArrayHasKey('total_bytes', $data);
		$this->assertArrayHasKey('costs', $data);
		$this->assertArrayHasKey('r2', $data['costs']);
		$this->assertArrayHasKey('s3', $data['costs']);
		$this->assertNotSame('', json_encode($data));
	}

	#[Test]
	#[TestDox('an empty site projects to nothing rather than dividing by zero')]
	#[Group('strata/estimate')]
	public function emptySiteProjectsToAlmostNothing(): void
	{
		$projection = (new Estimator())->project(new Measurement());

		$this->assertSame(0, $projection->treeBytes);
		$this->assertGreaterThan(
			0,
			$projection->totalBytes(),
			'state and config writes still happen',
		);
		$this->assertSame(0.0, $projection->share('nothing-stored-here'));
	}

	#endregion
}
