<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\field;

use Drupal\strata\Capture\Classifier\Classification;
use Drupal\views\Attribute\ViewsField;

/**
 * Renders a classification value as its label.
 *
 * @see Classification
 */
#[ViewsField('strata_classification')]
final class ClassificationField extends EnumField
{
	/**
	 * {@inheritdoc}
	 */
	public static function options(): array
	{
		return self::labelled(Classification::cases());
	}
}
