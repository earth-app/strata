<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\filter;

use Drupal\strata\Plugin\views\field\VerbField;
use Drupal\views\Attribute\ViewsFilter;

/**
 * Filters on the verb column, offering the verbs that exist.
 *
 * @see VerbField
 */
#[ViewsFilter('strata_verb')]
final class VerbFilter extends EnumFilter
{
	/**
	 * {@inheritdoc}
	 */
	protected function options(): array
	{
		return VerbField::options();
	}
}
