<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\field;

use Drupal\strata\Health\Finding;
use Drupal\views\Attribute\ViewsField;

/**
 * Renders a severity ordinal as its name.
 *
 * The one column of the four holding an integer rather than a string, because the ledger stores the
 * ordinal so a filter can ask for "at least a warning" with a numeric comparison.
 *
 * @see Finding
 */
#[ViewsField('strata_severity')]
final class SeverityField extends EnumField
{
	/**
	 * {@inheritdoc}
	 */
	public static function options(): array
	{
		return Finding::severities();
	}
}
