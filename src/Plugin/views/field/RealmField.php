<?php

declare(strict_types=1);

namespace Drupal\strata\Plugin\views\field;

use Drupal\strata\Journal\Realm;
use Drupal\views\Attribute\ViewsField;

/**
 * Renders a realm value as its label.
 *
 * @see Realm
 */
#[ViewsField('strata_realm')]
final class RealmField extends EnumField
{
	/**
	 * {@inheritdoc}
	 */
	public static function options(): array
	{
		return self::labelled(Realm::cases());
	}
}
