<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\filter;

use Drupal\strata\Plugin\views\field\ClassificationField;
use Drupal\views\Attribute\ViewsFilter;

/**
 * Filters on the classification column, offering the classifications that exist.
 *
 * @see ClassificationField
 */
#[ViewsFilter('strata_classification')]
final class ClassificationFilter extends EnumFilter
{
	/**
	 * {@inheritdoc}
	 */
	protected function options(): array
	{
		return ClassificationField::options();
	}
}
