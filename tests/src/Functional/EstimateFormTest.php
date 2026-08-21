<?php

declare(strict_types=1);

namespace Drupal\Tests\strata\Functional;

use Drupal\strata\Estimate\Measurement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Proves the estimator answers a shape of site with bytes, dollars and free-tier headroom.
 *
 * The projection reads nothing about this site and saves nothing, so what there is to test is the
 * page: the form has to rebuild with the projection attached, and the projection has to arrive as
 * three tables and a verdict rather than as one number nobody can argue with.
 *
 * Every figure it multiplies is a measured constant of the pipeline, which is why the page names
 * where the bytes go and which allowance binds first instead of only printing a total.
 */
#[RunTestsInSeparateProcesses]
class EstimateFormTest extends StrataFunctionalTestBase
{
	/**
	 * The submit button.
	 */
	public const PROJECT = 'Project';

	/**
	 * The path the estimator is served at.
	 */
	public const PATH = '/admin/config/system/strata/estimate';

	/**
	 * {@inheritdoc}
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->user(['view strata cost estimates']);
	}

	/**
	 * A shape of site to project.
	 *
	 * @param array<string, mixed> $overrides
	 *   Fields to change.
	 *
	 * @return array<string, mixed>
	 *   Field names keyed to the values to submit.
	 */
	private function shape(array $overrides = []): array
	{
		return $overrides + [
			'users' => '20000',
			'nodes' => '60000',
			'active_share' => '0.1',
			'file_bytes' => '0',
			'capture_files' => false,
			'access_churn' => Measurement::ACCESS_EVENT,
			'segment_interval' => '15',
			'base_interval' => '14400',
			'retention_days' => '365',
		];
	}

	#region The Form

	#[Test]
	#[TestDox('the estimator renders with the shape of a site already filled in')]
	#[Group('strata/functional')]
	public function estimatorRendersItsDefaults(): void
	{
		$this->drupalGet(self::PATH);

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->fieldValueEquals('users', '1000');
		$this->assertSession()->fieldValueEquals('nodes', '5000');
		$this->assertSession()->fieldValueEquals('segment_interval', '15');
		$this->assertSession()->fieldValueEquals('base_interval', '14400');
		$this->assertSession()->fieldValueEquals('retention_days', '365');
		$this->assertSession()->buttonExists(self::PROJECT);
		$this->assertSession()->pageTextNotContains('Where the Bytes Go');
	}

	#endregion

	#region The Projection

	#[Test]
	#[TestDox('a projection reports gigabytes, dollars on r2 and s3, and what binds first')]
	#[Group('strata/functional')]
	public function projectionReportsBytesDollarsAndHeadroom(): void
	{
		$this->drupalGet(self::PATH);
		$this->submitForm($this->shape(), self::PROJECT);

		$this->assertSession()->statusCodeEquals(200);

		$this->assertSession()->pageTextContains('GB over 365 days');
		$this->assertSession()->pageTextContains('requests a month');
		$this->assertSession()->pageTextContains('/mo on R2 and');
		$this->assertSession()->pageTextContains('/mo on S3');

		$this->assertSession()->pageTextContains('Where the Bytes Go');
		$this->assertSession()->pageTextContains('Cost a Month');
		$this->assertSession()->pageTextMatches('/\$[0-9]/');

		$this->assertSession()->pageTextContains('Cloudflare R2 Free Tier');
		$this->assertSession()->pageTextContains('of the storage allowance');
		$this->assertSession()->pageTextContains('of the write allowance');
		$this->assertSession()->pageTextContains('binding');
	}

	#[Test]
	#[TestDox('the projection keeps the shape that was submitted so it can be adjusted')]
	#[Group('strata/functional')]
	public function projectionKeepsTheSubmittedShape(): void
	{
		$this->drupalGet(self::PATH);
		$this->submitForm($this->shape(['retention_days' => 90]), self::PROJECT);

		$this->assertSession()->fieldValueEquals('users', '20000');
		$this->assertSession()->fieldValueEquals('nodes', '60000');
		$this->assertSession()->fieldValueEquals('retention_days', '90');
		$this->assertSession()->pageTextContains('GB over 90 days');
	}

	#[Test]
	#[TestDox('capturing files adds them to the breakdown and says what share they are')]
	#[Group('strata/functional')]
	public function capturedFilesAppearInTheBreakdown(): void
	{
		$this->drupalGet(self::PATH);
		$this->submitForm(
			$this->shape(['capture_files' => true, 'file_bytes' => 50_000_000_000]),
			self::PROJECT,
		);

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Worth Knowing');
		$this->assertSession()->pageTextContains('get almost none of the compression benefit');
	}

	#[Test]
	#[TestDox('switching access churn off is reported as costing more rather than less')]
	#[Group('strata/functional')]
	public function accessChurnOffIsReportedAsCostingMore(): void
	{
		$this->drupalGet(self::PATH);
		$this->submitForm($this->shape(['access_churn' => Measurement::ACCESS_OFF]), self::PROJECT);

		$this->assertSession()->statusCodeEquals(200);
		$this->assertSession()->pageTextContains('Worth Knowing');
		$this->assertSession()->pageTextContains('costs more than the event mode rather than less');
	}

	#endregion
}
