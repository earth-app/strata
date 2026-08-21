<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\filter;

use Drupal\views\Plugin\views\filter\InOperator;

/**
 * Filters on a stored enum value, offering only the values that exist.
 *
 * A string filter on the realm column asks an operator to type `keyvalue` and get it right. This offers
 * the set instead, labelled, which is both easier and impossible to typo - and the set comes from the
 * enum itself, so a realm added in a later release appears here without anything being updated.
 *
 * There is a subclass per column rather than one filter for all four because `ViewsFilter` is not a
 * repeatable attribute, so a plugin id needs a class of its own.
 */
abstract class EnumFilter extends InOperator
{
	/**
	 * The values this column can hold.
	 *
	 * @return array<string|int, string>
	 *   Stored value keyed to label.
	 */
	abstract protected function options(): array;

	/**
	 * {@inheritdoc}
	 *
	 * @return array<string|int, string>
	 *   Stored value keyed to label.
	 */
	public function getValueOptions(): array
	{
		// built rather than cached: an enum's cases are a handful of entries and never change at runtime
		return $this->valueOptions = $this->options();
	}
}
