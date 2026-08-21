<?php

declare(strict_types=1);

namespace Drupal\strata\Diff;

use Drupal\strata\Journal\Realm;
use JsonSerializable;

/**
 * What changed about one subject between two commits.
 *
 * The mode is derived from the realm rather than passed in, because the realm is what decides how a
 * subject is rendered: an entity has fields with human labels, a config object has a settings tree, a
 * table row has columns, and a file has blocks and no readable content at all. A renderer that had to
 * be told the mode could be told the wrong one.
 *
 * @see DiffBuilder
 * @see FieldDiff
 */
final class SubjectDiff implements JsonSerializable
{
	/**
	 * The subject exists at both commits and differs.
	 */
	public const CHANGED = 'changed';

	/**
	 * The subject exists only at the later commit.
	 */
	public const ADDED = 'added';

	/**
	 * The subject exists only at the earlier commit.
	 */
	public const REMOVED = 'removed';

	/**
	 * The subject could not be read at one or both commits.
	 */
	public const UNREADABLE = 'unreadable';

	/**
	 * An entity, rendered as fields.
	 */
	public const MODE_ENTITY = 'entity';

	/**
	 * A configuration object, rendered as a settings tree.
	 */
	public const MODE_CONFIG = 'config';

	/**
	 * A database row, rendered as columns.
	 */
	public const MODE_ROW = 'row';

	/**
	 * A file, rendered as blocks and sizes.
	 */
	public const MODE_FILE = 'file';

	/**
	 * Anything else, rendered as key and value.
	 */
	public const MODE_VALUE = 'value';

	/**
	 * Constructs a subject diff.
	 *
	 * @param string $subject
	 *   Subject path, in the form `<realm>/<name>`.
	 * @param Realm|null $realm
	 *   The realm, or NULL when the path names one this release does not know.
	 * @param string $status
	 *   One of CHANGED, ADDED, REMOVED or UNREADABLE.
	 * @param list<FieldDiff> $fields
	 *   What changed inside it.
	 * @param string $label
	 *   A human-readable name for the subject, when one is known.
	 * @param string|null $problem
	 *   Why it could not be read, when it could not.
	 */
	public function __construct(
		public readonly string $subject,
		public readonly ?Realm $realm,
		public readonly string $status,
		public readonly array $fields = [],
		public readonly string $label = '',
		public readonly ?string $problem = null,
	) {}

	/**
	 * How this subject should be rendered.
	 *
	 * @return string
	 *   One of the MODE constants.
	 */
	public function mode(): string
	{
		return match ($this->realm) {
			Realm::ENTITY => self::MODE_ENTITY,
			Realm::CONFIG => self::MODE_CONFIG,
			Realm::TABLE => self::MODE_ROW,
			Realm::FILE => self::MODE_FILE,
			default => self::MODE_VALUE,
		};
	}

	/**
	 * The part of the subject path after the realm.
	 *
	 * @return string
	 *   The name.
	 */
	public function name(): string
	{
		$at = strpos($this->subject, '/');

		return $at === false ? $this->subject : substr($this->subject, $at + 1);
	}

	/**
	 * What to show as this subject's title.
	 *
	 * @return string
	 *   The label when one is known, otherwise the name.
	 */
	public function title(): string
	{
		return $this->label !== '' ? $this->label : $this->name();
	}

	/**
	 * How many fields changed.
	 *
	 * @return int
	 *   The count.
	 */
	public function changedFields(): int
	{
		return count($this->fields);
	}

	/**
	 * Whether this subject could be read at both commits.
	 *
	 * @return bool
	 *   FALSE when something did not decode.
	 */
	public function isReadable(): bool
	{
		return $this->status !== self::UNREADABLE;
	}

	/**
	 * A one-line summary.
	 *
	 * @return string
	 *   What happened to this subject.
	 */
	public function summary(): string
	{
		if (!$this->isReadable()) {
			return sprintf('%s could not be read: %s', $this->title(), (string) $this->problem);
		}

		return match ($this->status) {
			self::ADDED => sprintf('%s was created', $this->title()),
			self::REMOVED => sprintf('%s was removed', $this->title()),
			default => sprintf(
				'%s changed in %d field%s: %s',
				$this->title(),
				$this->changedFields(),
				$this->changedFields() === 1 ? '' : 's',
				implode(
					', ',
					array_map(static fn(FieldDiff $f): string => $f->name, $this->fields),
				),
			),
		};
	}

	/**
	 * {@inheritdoc}
	 */
	public function jsonSerialize(): array
	{
		return [
			'subject' => $this->subject,
			'realm' => $this->realm?->value,
			'mode' => $this->mode(),
			'status' => $this->status,
			'label' => $this->label,
			'problem' => $this->problem,
			'fields' => $this->fields,
		];
	}
}
