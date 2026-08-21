<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\field;

use Drupal\strata\Journal\Verb;
use Drupal\views\Attribute\ViewsField;

/**
 * Renders a verb value as its label.
 *
 * @see Verb
 */
#[ViewsField('strata_verb')]
final class VerbField extends EnumField
{
	/**
	 * {@inheritdoc}
	 */
	public static function options(): array
	{
		return self::labelled(Verb::cases());
	}
}
