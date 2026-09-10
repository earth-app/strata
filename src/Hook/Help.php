<?php

declare(strict_types=1);

namespace Drupal\strata\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Help for the engine module and the pages it owns.
 *
 * The engine had no help page at all until 1.0.3, so `/admin/help` listed nothing for Strata and the
 * Extend page showed no Help link beside it. The storage settings form is the `configure:` target in
 * `strata.info.yml` and therefore the first page a new install lands on, and it was the one page in
 * the module with no help of any kind.
 *
 * The module page carries the ordered setup steps, because nothing else in the UI states them.
 *
 * Returned as a list of short sentences rather than one paragraph: `Drupal.Semantics.FunctionT.Concat`
 * forbids concatenating inside `t()`, and it is right to, because the extractor cannot read a
 * concatenated argument and the string would ship untranslatable.
 */
final class Help
{
	use StringTranslationTrait;

	/**
	 * Implements hook_help().
	 *
	 * @param string $routeName
	 *   The current route.
	 * @param RouteMatchInterface $routeMatch
	 *   The route match.
	 *
	 * @return array<string, mixed>
	 *   A render array, empty for a route with no help.
	 */
	#[Hook('help')]
	public function forRoute(string $routeName, RouteMatchInterface $routeMatch): array
	{
		$points = $this->points($routeName);

		return $points === [] ? [] : ['#theme' => 'item_list', '#items' => $points];
	}

	/**
	 * What one page's help says.
	 *
	 * @param string $routeName
	 *   The current route.
	 *
	 * @return list<string>
	 *   One sentence each, empty for a route with no help.
	 */
	private function points(string $routeName): array
	{
		return match ($routeName) {
			'help.page.strata' => [
				(string) $this->t(
					'Strata records every change this site makes and can put it back to any instant.',
				),
				(string) $this->t('It ships doing nothing. Four steps turn it on, in this order.'),
				(string) $this->t(
					'1. On the Storage tab, press Generate a Key. It makes one of the right length.',
				),
				(string) $this->t(
					'2. On the same tab, choose where backups go and tick Capture is On.',
				),
				(string) $this->t(
					'3. On the Capture tab, choose what is watched. The defaults suit most sites.',
				),
				(string) $this->t('4. Seal the first window, either on cron or from Seal Now.'),
				(string) $this->t(
					'Then admin/reports/strata says whether the site is backed up right now.',
				),
				(string) $this->t(
					'Skipping step 1 needs the cipher set to none, which stores frames in the clear.',
				),
				(string) $this->t(
					'A generated key lives in this site configuration, so keep a copy elsewhere.',
				),
				(string) $this->t(
					'From a shell the first and last steps are drush strata:new-key and drush strata:flush.',
				),
			],
			'strata.settings' => [
				(string) $this->t(
					'Storage is where backups go, and the only page that must be set.',
				),
				(string) $this->t(
					'Capture chooses what is watched; Retention chooses what is kept.',
				),
				(string) $this->t('The rest are optional and safe to leave alone.'),
			],
			'strata.settings.storage' => [
				(string) $this->t('Nothing is captured until Capture is On is ticked here.'),
				(string) $this->t(
					'The provider is where objects go. Local writes to a directory on this server.',
				),
				(string) $this->t(
					'A remote provider needs its own Strata submodule enabled before it is offered.',
				),
				(string) $this->t(
					'The key seals every frame before it leaves this server. Losing it loses the history.',
				),
				(string) $this->t(
					'The durability window is how much work a host failure would cost.',
				),
			],
			'strata.settings.capture' => [
				(string) $this->t('A realm that is off is not captured and cannot be restored.'),
				(string) $this->t(
					'Turning one off later does not remove what was already captured.',
				),
				(string) $this->t(
					'Statement capture costs under 10 microseconds a mutation, measured.',
				),
				(string) $this->t(
					'That is about 0.6 milliseconds on a request making 200 queries.',
				),
				(string) $this->t(
					'It is the only source that costs anything when nothing changes.',
				),
				(string) $this->t(
					'Run drush strata:calibrate for the numbers this host actually reaches.',
				),
			],
			'strata.settings.retention' => [
				(string) $this->t('Each level folds the one below it into wider windows on cron.'),
				(string) $this->t(
					'Folding never removes a restore point; it recompresses and shortens chains.',
				),
				(string) $this->t('Only a prune deletes, and a prune writes a receipt first.'),
				(string) $this->t('The anchor interval decides how much work a restore does.'),
				(string) $this->t(
					'It is a weak lever on stored bytes: the two effects largely cancel.',
				),
				(string) $this->t('Collapse gains almost nothing below the day level.'),
			],
			'strata.settings.tiers' => [
				(string) $this->t('Tiers move older objects to a cheaper store as they age.'),
				(string) $this->t(
					'Leave this off unless one bucket is measurably costing too much.',
				),
			],
			'strata.settings.webhooks' => [
				(string) $this->t(
					'Posts a signed payload when a restore, a budget or a finding needs a person.',
				),
				(string) $this->t(
					'A delivery follows no redirect, so the endpoint cannot forward it elsewhere.',
				),
			],
			'strata.settings.telemetry' => [
				(string) $this->t('Exports the pipeline metrics to an OTLP endpoint on cron.'),
				(string) $this->t('Nothing here changes what is captured or stored.'),
			],
			default => [],
		};
	}
}
