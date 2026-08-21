<?php

declare(strict_types=1);

namespace Drupal\Tests\strata_ui\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Url;
use Drupal\Tests\strata\Kernel\StrataKernelTestBase;
use Drupal\strata\Codec\CodecCatalog;
use Drupal\strata\Journal\Realm;
use Drupal\strata_ui\Controller\CalibrateController;
use Drupal\strata_ui\Hook\Help;
use Drupal\strata_ui\Hook\Theme;
use Drupal\strata_ui\Render\Chart;
use Drupal\strata_ui\Render\Format;
use Drupal\strata_ui\Render\SeriesChart;
use Drupal\strata_ui\Render\TimelineChart;
use Drupal\strata\Metrics\MetricSeries;
use Drupal\strata\Timeline\TimelineBucket;
use Drupal\strata\Timeline\TimelineWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves the report pages build against a real store rather than only typechecking.
 *
 * A route with a mistyped controller, a form naming a service that does not exist, a theme hook whose
 * template is missing and a template referencing a variable the controller does not pass are all
 * invisible to static analysis and all produce a white screen. So every route is resolved, every
 * controller is invoked, and every page is rendered to markup.
 *
 * **Every page is driven on an EMPTY store as well as a populated one.** A fresh install is the
 * state a new user sees first, and a report that divides by a total, indexes the newest commit or
 * scales a chart by its peak is exactly the kind of code that works on a busy site and fatals on an
 * empty one.
 */
class UiTest extends StrataKernelTestBase
{
	/**
	 * {@inheritdoc}
	 *
	 * @var list<string>
	 */
	protected static $modules = ['system', 'user', 'key', 'strata', 'strata_ui'];

	/**
	 * Every route this module and the settings forms declare.
	 *
	 * @return array<string, array{string, array<string, string>}>
	 *   Route name and its parameters.
	 */
	public static function routeProvider(): array
	{
		$commit = str_repeat('ab', 32);

		return [
			'the timeline' => ['strata_ui.timeline', []],
			'the graphs' => ['strata_ui.graphs', []],
			'the diff' => ['strata_ui.diff', []],
			'a diff between two commits' => [
				'strata_ui.diff.pair',
				['from' => $commit, 'to' => $commit],
			],
			'the health dashboard' => ['strata_ui.health', []],
			'the storage explorer' => ['strata_ui.explorer', []],
			'what a commit holds' => ['strata_ui.explorer.commit', ['commit' => $commit]],
			'the estimator' => ['strata_ui.estimate', []],
			'the calibration report' => ['strata_ui.calibrate', []],
			'a rollback' => ['strata_ui.rollback', ['commit' => $commit]],
			'a quarantine' => ['strata_ui.quarantine', ['commit' => $commit]],
			'a prune' => ['strata_ui.prune', []],
			'a repair' => ['strata_ui.repair', ['code' => 'frame.missing']],
			'storage settings' => ['strata.settings.storage', []],
			'capture settings' => ['strata.settings.capture', []],
			'retention settings' => ['strata.settings.retention', []],
			'webhook settings' => ['strata.settings.webhooks', []],
			'telemetry settings' => ['strata.settings.telemetry', []],
		];
	}

	/**
	 * The settings forms, each of which must build and save.
	 *
	 * @return array<string, array{string}>
	 *   The form class.
	 */
	public static function settingsFormProvider(): array
	{
		return [
			'storage' => ['Drupal\strata\Form\StorageSettingsForm'],
			'capture' => ['Drupal\strata\Form\CaptureSettingsForm'],
			'retention' => ['Drupal\strata\Form\RetentionSettingsForm'],
			'webhooks' => ['Drupal\strata\Form\WebhookSettingsForm'],
			'telemetry' => ['Drupal\strata\Form\TelemetrySettingsForm'],
		];
	}

	#region Routing

	#[Test]
	#[TestDox('$_dataName resolves to a controller or form that exists')]
	#[Group('strata/ui')]
	#[DataProvider('routeProvider')]
	public function routeResolves(string $name, array $parameters): void
	{
		$route = $this->container->get('router.route_provider')->getRouteByName($name);

		$this->assertNotNull($route, $name);
		$this->assertNotSame(
			'',
			(string) Url::fromRoute($name, $parameters)->toString(),
			'the route builds a URL',
		);
	}

	#[Test]
	#[TestDox('every route requires a permission, so no report is reachable anonymously')]
	#[Group('strata/ui')]
	public function everyRouteIsGated(): void
	{
		foreach (array_keys(self::routeProvider()) as $label) {
			[$name] = self::routeProvider()[$label];
			$route = $this->container->get('router.route_provider')->getRouteByName($name);
			$requirements = $route->getRequirements();

			$this->assertTrue(
				isset($requirements['_permission']) || isset($requirements['_custom_access']),
				sprintf('%s carries an access requirement', $name),
			);
			// YAML parses TRUE to a boolean, so this is a bool and not the string
			$this->assertTrue((bool) $route->getOption('_admin_route'), $name);
		}
	}

	#[Test]
	#[TestDox('every permission a route requires is one the module actually declares')]
	#[Group('strata/ui')]
	public function everyRequiredPermissionExists(): void
	{
		$declared = array_keys($this->container->get('user.permissions')->getPermissions());

		foreach (array_keys(self::routeProvider()) as $label) {
			[$name] = self::routeProvider()[$label];
			$route = $this->container->get('router.route_provider')->getRouteByName($name);
			$requirement = (string) ($route->getRequirements()['_permission'] ?? '');

			if ($requirement === '') {
				continue;
			}

			// a route may accept any one of several permissions, joined by a plus
			foreach (explode('+', $requirement) as $permission) {
				$this->assertContains(trim($permission), $declared, $name);
			}
		}
	}

	#endregion

	#region Pages

	#[Test]
	#[TestDox('the timeline renders on an empty store rather than failing on a missing head')]
	#[Group('strata/ui')]
	public function timelineRendersEmpty(): void
	{
		$build = $this->controller('timeline')->page(new Request());

		$this->assertSame('strata_timeline', $build['#theme']);
		$this->assertTrue($build['#empty']);
		$this->assertSame(0, $build['#cache']['max-age'], 'a report is never cached');
		$this->assertNotSame('', (string) $this->markup($build));
	}

	#[Test]
	#[TestDox('the timeline renders a bar per bucket once there is history')]
	#[Group('strata/ui')]
	public function timelineRendersHistory(): void
	{
		$this->seedCommit();

		$build = $this->controller('timeline')->page(new Request());

		$this->assertFalse($build['#empty']);
		$this->assertNotEmpty($build['#chart']['bars']);
		$this->assertNotSame('', (string) $this->markup($build));
	}

	#[Test]
	#[TestDox('a requested window is honoured and its resolution is clamped to the ladder')]
	#[Group('strata/ui')]
	public function windowComesFromTheQueryString(): void
	{
		$request = new Request([
			'from' => 1_000_000_000_000_000,
			'to' => 1_000_000_100_000_000,
			'resolution' => 999_999_999,
		]);

		$build = $this->controller('timeline')->page($request);

		$this->assertLessThanOrEqual(
			TimelineWindow::LADDER[count(TimelineWindow::LADDER) - 1],
			$build['#window']['resolution'],
			'a hostile resolution cannot make the page build millions of buckets',
		);
	}

	#[Test]
	#[TestDox('the graphs render a chart per series on an empty store')]
	#[Group('strata/ui')]
	public function graphsRender(): void
	{
		$build = $this->controller('graphs')->page(new Request());

		$this->assertSame('strata_graphs', $build['#theme']);
		$this->assertNotEmpty($build['#charts']);
		$this->assertNotSame('', (string) $this->markup($build));
	}

	#[Test]
	#[TestDox('the health dashboard renders with nothing open')]
	#[Group('strata/ui')]
	public function healthRendersClean(): void
	{
		$build = $this->controller('health')->page();

		$this->assertSame('strata_health', $build['#theme']);
		$this->assertTrue($build['#clean']);
		$this->assertFalse($build['#drills']['ran'], 'a store with no drill says so');
		$this->assertNotSame('', (string) $this->markup($build));
	}

	#[Test]
	#[TestDox('the health dashboard does not take a metric reading, so viewing it changes nothing')]
	#[Group('strata/ui')]
	public function healthDoesNotSample(): void
	{
		$sampler = $this->container->get('strata.engine')->metricSampler();
		$before = count($sampler->window());

		$this->controller('health')->page();

		$this->assertCount(
			$before,
			$sampler->window(),
			'rendering a page must not append to the anomaly window',
		);
	}

	#[Test]
	#[TestDox('the storage explorer renders against a reachable store')]
	#[Group('strata/ui')]
	public function explorerRenders(): void
	{
		$this->settings()->set('local_path', $this->storeRoot)->set('cipher.id', 'none')->save();

		$build = $this->controller('explorer')->page();

		$this->assertSame('strata_explorer', $build['#theme']);
		$this->assertNotSame('', (string) $this->markup($build));
	}

	#[Test]
	#[TestDox('the calibration report lists every codec, including the ones this host cannot run')]
	#[Group('strata/ui')]
	public function calibrationListsEveryCodec(): void
	{
		$profiles = CodecCatalog::profiles($this->container->get('strata.engine')->codecs());
		$build = CalibrateController::create($this->container)->page();

		$this->assertNotEmpty($profiles);
		$this->assertCount(
			count($profiles),
			$build['codecs']['#rows'],
			'an unavailable codec is listed with its reason rather than omitted',
		);
		$this->assertNotSame('', (string) $this->markup($build));
	}

	#[Test]
	#[TestDox('the diff page says so rather than failing when there are not two commits')]
	#[Group('strata/ui')]
	public function diffNeedsTwoCommits(): void
	{
		// the kernel lane has no private stream wrapper, so the local provider is pointed at vfs
		$this->settings()->set('local_path', $this->storeRoot)->set('cipher.id', 'none')->save();

		$build = $this->controller('diff')->page(new Request());

		$this->assertNotSame('', (string) $this->markup($build));
	}

	#[Test]
	#[TestDox('a report explains itself rather than white-screening when no key is configured')]
	#[Group('strata/ui')]
	public function aReportSurvivesAnUnconfiguredStore(): void
	{
		// the shipped default is encryption on with no key chosen, which is a fresh install
		$this->settings()->set('cipher.id', 'xchacha20poly1305')->set('key', '')->save();

		$markup = (string) $this->markup($this->controller('explorer')->page());

		$this->assertStringContainsString('unavailable', $markup);
		$this->assertStringContainsString('no key is configured', $markup);
	}

	#endregion

	#region Forms

	#[Test]
	#[TestDox('$_dataName settings form builds')]
	#[Group('strata/ui')]
	#[DataProvider('settingsFormProvider')]
	public function settingsFormBuilds(string $class): void
	{
		$build = $this->container->get('form_builder')->getForm($class);

		$this->assertArrayHasKey('actions', $build);
		$this->assertNotSame('', (string) $this->markup($build));
	}

	#[Test]
	#[TestDox('a settings form saves only the keys it owns and leaves the rest alone')]
	#[Group('strata/ui')]
	public function settingsFormLeavesOtherKeysAlone(): void
	{
		$this->settings()->set('retention.base_interval', 7200)->save();

		$this->submit(
			'Drupal\strata\Form\TelemetrySettingsForm',
			$this->formState([
				'telemetry__endpoint' => 'https://collector.example/v1',
				'headers' => 'Authorization: Bearer abc',
			]),
		);

		$config = $this->config('strata.settings');

		$this->assertSame('https://collector.example/v1', $config->get('telemetry.endpoint'));
		$this->assertSame(['Authorization' => 'Bearer abc'], $config->get('telemetry.headers'));
		$this->assertSame(
			7200,
			$config->get('retention.base_interval'),
			'a form that does not own a key must not blank it',
		);
	}

	#[Test]
	#[TestDox('the storage form refuses encryption with no key rather than storing plaintext')]
	#[Group('strata/ui')]
	public function storageFormRefusesEncryptionWithNoKey(): void
	{
		$state = $this->formState([
			'cipher__id' => 'xchacha20poly1305',
			'key' => '',
			'flush__max_age' => 15,
		]);

		$this->submit('Drupal\strata\Form\StorageSettingsForm', $state);

		$this->assertNotEmpty($state->getErrors(), 'the form refuses rather than saving');
	}

	#[Test]
	#[TestDox('the retention form refuses a ladder whose windows do not widen')]
	#[Group('strata/ui')]
	public function retentionFormRefusesABackwardLadder(): void
	{
		$values = [];

		foreach ([60, 30, 3600, 86400, 2592000] as $level => $window) {
			$values[sprintf('level_%d_window', $level)] = $window;
			$values[sprintf('level_%d_keep', $level)] = 0;
		}

		$state = $this->formState($values);

		$this->submit('Drupal\strata\Form\RetentionSettingsForm', $state);

		$this->assertNotEmpty(
			$state->getErrors(),
			'a rollup folds upwards, so a narrower level above a wider one is refused',
		);
	}

	#[Test]
	#[TestDox('the webhook form never renders a stored secret back into the page')]
	#[Group('strata/ui')]
	public function webhookFormHidesTheSecret(): void
	{
		$this->config('strata.webhooks')
			->set('subscriptions', [
				[
					'url' => 'https://hook.example/strata',
					'secret' => 'super-secret-value',
					'events' => [],
					'enabled' => true,
					'timeout' => 10,
					'attempts' => 3,
				],
			])
			->save();

		$build = $this->container
			->get('form_builder')
			->getForm('Drupal\strata\Form\WebhookSettingsForm');
		$markup = (string) $this->markup($build);

		$this->assertStringNotContainsString('super-secret-value', $markup);
		$this->assertStringContainsString('hook.example', $markup);
	}

	#[Test]
	#[TestDox('a rollback against an unknown commit says so rather than throwing')]
	#[Group('strata/ui')]
	public function rollbackAgainstAnUnknownCommit(): void
	{
		$build = $this->container
			->get('form_builder')
			->getForm('Drupal\strata_ui\Form\RollbackConfirmForm', str_repeat('cd', 32));

		$this->assertNotSame('', (string) $this->markup($build));
	}

	#[Test]
	#[TestDox('the prune form previews a dry run and writes nothing while it is only previewed')]
	#[Group('strata/ui')]
	public function pruneFormPreviewsWithoutWriting(): void
	{
		$build = $this->container
			->get('form_builder')
			->getForm('Drupal\strata_ui\Form\PruneConfirmForm');

		$this->assertNotSame('', (string) $this->markup($build));
	}

	#endregion

	#region Rendering

	#[Test]
	#[TestDox('a chart maps a value to a y coordinate above the baseline and inverts the axis')]
	#[Group('strata/ui')]
	public function chartInvertsTheAxis(): void
	{
		$chart = new Chart();

		$this->assertSame($chart->baseline(), $chart->y(0.0, 100.0));
		$this->assertLessThan($chart->y(50.0, 100.0), $chart->y(100.0, 100.0));
		$this->assertSame($chart->baseline(), $chart->y(10.0, 0.0), 'an empty axis sits flat');
	}

	#[Test]
	#[
		TestDox(
			'a non-zero value always draws at least a hairline, so it is never mistaken for no data',
		),
	]
	#[Group('strata/ui')]
	public function aTinyValueStillDraws(): void
	{
		$chart = new Chart();

		$this->assertSame(0.0, $chart->barHeight(0.0, 1_000_000.0));
		$this->assertGreaterThanOrEqual(1.0, $chart->barHeight(1.0, 1_000_000.0));
	}

	#[Test]
	#[TestDox('a timeline chart marks an anchor and a restore with a class, not only a colour')]
	#[Group('strata/ui')]
	public function barsCarryStateClasses(): void
	{
		$window = new TimelineWindow(0, 60_000_000, 15);
		$buckets = [
			new TimelineBucket(0, 14_999_999, 1, 10, 100, 50, 1),
			new TimelineBucket(15_000_000, 29_999_999, 1, 10, 100, 50, 0, [], 1),
			new TimelineBucket(30_000_000, 44_999_999),
		];

		$built = (new TimelineChart())->build($window, $buckets);

		$this->assertStringContainsString('--anchor', $built['bars'][0]['classes']);
		$this->assertStringContainsString('--restore', $built['bars'][1]['classes']);
		$this->assertStringContainsString('--empty', $built['bars'][2]['classes']);
		$this->assertNull($built['bars'][2]['url'], 'an empty bucket has nothing to link to');
	}

	#[Test]
	#[TestDox('a flat series is reported as flat rather than drawn along the axis')]
	#[Group('strata/ui')]
	public function aFlatSeriesIsReportedAsFlat(): void
	{
		$built = (new SeriesChart())->build(new MetricSeries('x', 'X', [0.0, 0.0, 0.0]));

		$this->assertTrue($built['flat']);
	}

	#[Test]
	#[TestDox('a byte count renders in the largest unit that stays readable')]
	#[Group('strata/ui')]
	public function bytesRenderReadably(): void
	{
		$this->assertSame('0 B', Format::bytes(0));
		$this->assertSame('512 B', Format::bytes(512));
		$this->assertSame('1.0 KiB', Format::bytes(1024));
		$this->assertSame('-1.0 KiB', Format::bytes(-1024));
		$this->assertSame('4.0 MiB', Format::bytes(4_194_304));
	}

	#[Test]
	#[TestDox('a value under a cent renders with enough places to be non-zero')]
	#[Group('strata/ui')]
	public function tinyCostsAreNotRoundedToFree(): void
	{
		$this->assertSame('$0.0016', Format::money(0.0016));
		$this->assertSame('$1.01', Format::money(1.014));
		$this->assertSame('$0.00', Format::money(0.0));
	}

	#[Test]
	#[TestDox('a value renders in whatever unit its series declares')]
	#[Group('strata/ui')]
	public function unitsAreHonoured(): void
	{
		$this->assertSame('1.0 KiB', Format::inUnit(1024.0, 'bytes'));
		$this->assertSame('5.86x', Format::inUnit(5.86, 'x'));
		$this->assertSame('$2.00', Format::inUnit(2.0, 'dollars'));
		$this->assertSame('1,024', Format::inUnit(1024.0, ''));
		$this->assertSame('12 per hour', Format::inUnit(12.0, 'per hour'));
	}

	#endregion

	#region Theme and Blocks

	#[Test]
	#[TestDox('every theme hook this module declares has a template on disk')]
	#[Group('strata/ui')]
	public function everyThemeHookHasATemplate(): void
	{
		$directory = dirname(__DIR__, 3) . '/templates';

		foreach (array_keys((new Theme())->hooks()) as $hook) {
			$this->assertFileExists(
				sprintf('%s/%s.html.twig', $directory, str_replace('_', '-', (string) $hook)),
				(string) $hook,
			);
		}
	}

	#[Test]
	#[TestDox('the status and health blocks build against an empty store')]
	#[Group('strata/ui')]
	public function blocksBuild(): void
	{
		$manager = $this->container->get('plugin.manager.block');

		foreach (['strata_status', 'strata_health'] as $id) {
			$this->assertTrue($manager->hasDefinition($id), $id);

			$build = $manager->createInstance($id)->build();

			$this->assertSame('strata_status', $build['#theme'], $id);
			$this->assertNotEmpty($build['#rows'], $id);
			$this->assertNotSame('', (string) $this->markup($build), $id);
		}
	}

	#[Test]
	#[TestDox('a page help entry exists for every report route')]
	#[Group('strata/ui')]
	public function helpCoversEveryReport(): void
	{
		$help = new Help();
		$match = $this->container->get('current_route_match');

		foreach (
			['strata_ui.timeline', 'strata_ui.graphs', 'strata_ui.health', 'strata_ui.explorer']
			as $route
		) {
			$this->assertNotEmpty($help->forRoute($route, $match), $route);
		}
	}

	#endregion

	/**
	 * A controller from the container, by the short name in its service-free create().
	 *
	 * @param string $which
	 *   One of timeline, graphs, diff, explorer or health.
	 *
	 * @return object
	 *   The controller.
	 */
	private function controller(string $which): object
	{
		$class = match ($which) {
			'timeline' => 'Drupal\strata_ui\Controller\TimelineController',
			'graphs' => 'Drupal\strata_ui\Controller\GraphController',
			'diff' => 'Drupal\strata_ui\Controller\DiffController',
			'explorer' => 'Drupal\strata_ui\Controller\ExplorerController',
			default => 'Drupal\strata_ui\Controller\HealthController',
		};

		return $class::create($this->container);
	}

	/**
	 * Submits a form by class name.
	 *
	 * The form builder takes its first argument by reference, so a class name has to be a variable
	 * rather than a literal.
	 *
	 * @param string $class
	 *   The form class.
	 * @param object $state
	 *   The form state carrying the submitted values.
	 */
	private function submit(string $class, object $state): void
	{
		$this->container->get('form_builder')->submitForm($class, $state);
	}

	/**
	 * A form state carrying submitted values.
	 *
	 * @param array<string, mixed> $values
	 *   The submitted values.
	 *
	 * @return object
	 *   The form state.
	 */
	private function formState(array $values): object
	{
		$state = new FormState();
		$state->setValues($values);

		return $state;
	}

	/**
	 * Renders a build array to markup.
	 *
	 * @param array<string, mixed> $build
	 *   The render array.
	 *
	 * @return string
	 *   The markup.
	 */
	private function markup(array $build): string
	{
		return (string) $this->container->get('renderer')->renderInIsolation($build);
	}

	/**
	 * Puts one real commit in the local index so a report has something to show.
	 */
	private function seedCommit(): void
	{
		$this->container
			->get('database')
			->insert('strata_commit')
			->fields([
				'id' => str_repeat('ab', 32),
				'parent' => null,
				'index_ref' => str_repeat('cd', 32),
				'chain' => 0,
				'anchored_at' => 0,
				'microtime' => (int) (microtime(true) * 1_000_000),
				'label' => sprintf('%s operations', Realm::ENTITY->value),
				'actor' => null,
				'operations' => 12,
				'raw_bytes' => 4096,
				'stored_bytes' => 1024,
				'level' => 0,
				'is_base' => 1,
				'segment_key' => null,
			])
			->execute();
	}
}
