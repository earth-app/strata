<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\filter;

use Drupal\strata\Plugin\views\field\RealmField;
use Drupal\views\Attribute\ViewsFilter;

/**
 * Filters on the realm column, offering the realms that exist.
 *
 * @see RealmField
 */
#[ViewsFilter('strata_realm')]
final class RealmFilter extends EnumFilter
{
	/**
	 * {@inheritdoc}
	 */
	protected function options(): array
	{
		return RealmField::options();
	}
}
