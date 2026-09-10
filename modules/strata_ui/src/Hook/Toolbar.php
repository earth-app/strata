<?php

declare(strict_types=1);

namespace Drupal\strata_ui\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\strata\Engine;
use Drupal\strata_ui\Render\Format;
use Throwable;

/**
 * A toolbar item carrying the one number worth glancing at.
 *
 * The recovery point lag - how much captured work would be lost if the host died now - is on the tab
 * itself rather than behind a click, because a number nobody looks at is not monitoring. Everything
 * else is one level down.
 *
 * **The item costs one local query and never touches the bucket.** It renders on every admin page
 * load, so reaching a storage provider here would put a network round trip into every one of them.
 * The lag comes from the local commit index and the pending count from the journal table.
 *
 * @see Engine
 */
final class Toolbar
{
	use StringTranslationTrait;

	/**
	 * Constructs the toolbar item.
	 *
	 * @param Engine $engine
	 *   Supplies the lag.
	 * @param AccountProxyInterface $currentUser
	 *   Decides whether the item renders at all.
	 */
	public function __construct(
		private readonly Engine $engine,
		private readonly AccountProxyInterface $currentUser,
	) {}

	/**
	 * Implements hook_toolbar().
	 *
	 * @return array<string, mixed>
	 *   The toolbar item.
	 */
	#[Hook('toolbar')]
	public function item(): array
	{
		if (!$this->currentUser->hasPermission('view strata timeline')) {
			return [];
		}

		// the whole body, not only the label: this renders on every admin page, and Url::fromRoute()
		// raises on a site whose router still names a route the module no longer registers
		try {
			return $this->build();
		} catch (Throwable) {
			return [];
		}
	}

	/**
	 * The toolbar item itself.
	 *
	 * @return array<string, mixed>
	 *   The item.
	 */
	private function build(): array
	{
		return [
			'strata' => [
				'#type' => 'toolbar_item',
				'tab' => [
					'#type' => 'link',
					'#title' => $this->label(),
					'#url' => Url::fromRoute('strata_ui.status'),
					'#attributes' => [
						'title' => $this->t('How much captured work is not yet sealed'),
						'class' => ['toolbar-icon', 'toolbar-icon-strata'],
					],
				],
				'tray' => [
					'#heading' => $this->t('Strata'),
					'links' => [
						'#theme' => 'links__toolbar_strata',
						'#links' => $this->links(),
						'#attributes' => ['class' => ['toolbar-menu']],
					],
				],
				'#weight' => 100,
				'#cache' => ['max-age' => 0],
			],
			'#cache' => ['contexts' => ['user.permissions']],
		];
	}

	/**
	 * The tab label, carrying the lag.
	 *
	 * @return string
	 *   The label.
	 */
	private function label(): string
	{
		// the whole label, not only the index read: this renders on every admin page, and building
		// the telemetry pass reads settings and can refuse on a site that has not finished setting
		// up. a toolbar item is the last thing that should be able to take an admin page down
		try {
			$newest = $this->engine->commitIndex()->newest();

			if ($newest === null) {
				return (string) $this->t('Strata: nothing captured');
			}

			return (string) $this->t('Strata: @lag behind', [
				'@lag' => Format::duration($this->engine->telemetryPass()->rpoLag()),
			]);
		} catch (Throwable) {
			return (string) $this->t('Strata');
		}
	}

	/**
	 * The links in the tray.
	 *
	 * @return array<string, array<string, mixed>>
	 *   Each link's title and URL.
	 */
	private function links(): array
	{
		$links = [
			'status' => [
				'title' => $this->t('Status'),
				'url' => Url::fromRoute('strata_ui.status'),
			],
			'timeline' => [
				'title' => $this->t('Timeline'),
				'url' => Url::fromRoute('strata_ui.timeline'),
			],
			'graphs' => [
				'title' => $this->t('Graphs'),
				'url' => Url::fromRoute('strata_ui.graphs'),
			],
		];

		if ($this->currentUser->hasPermission('view strata health')) {
			$links['health'] = [
				'title' => $this->t('Health'),
				'url' => Url::fromRoute('strata_ui.health'),
			];
		}
		if ($this->currentUser->hasPermission('administer strata')) {
			$links['settings'] = [
				'title' => $this->t('Settings'),
				'url' => Url::fromRoute('strata.settings.storage'),
			];
		}

		return $links;
	}
}
