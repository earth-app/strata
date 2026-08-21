<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\filter;

use Drupal\strata\Plugin\views\field\SeverityField;
use Drupal\views\Attribute\ViewsFilter;

/**
 * Filters on the severity column, offering the severities that exist.
 *
 * @see SeverityField
 */
#[ViewsFilter('strata_severity')]
final class SeverityFilter extends EnumFilter
{
	/**
	 * {@inheritdoc}
	 */
	protected function options(): array
	{
		return SeverityField::options();
	}
}
