<?php

declare(strict_types=1);

namespace Drupal\strata\Restore;

use Drupal\Core\Database\Connection;
use RuntimeException;

/**
 * How a table's contents get replaced.
 *
 * There is more than one way and they are not equivalent. Truncating and refilling works on every
 * driver and leaves the table empty for the duration; building a shadow table and renaming it into
 * place leaves the original readable until the instant of the swap, and is only atomic on drivers
 * that can rename two tables in one statement. A site in maintenance mode does not care about the
 * difference; a site restoring one table while staying up cares a great deal.
 *
 * Three rules bind every implementation:
 *
 * - **It reports whether it can run here, and why not.** A strategy that fails half way through on
 *   an unsupported driver has already truncated the table. `isSupported()` is checked before
 *   anything is touched, and an unsupported strategy is refused rather than attempted.
 * - **It is all or nothing.** A partially restored table is a state nobody described and nobody can
 *   reason about, so the work happens in a transaction or via a swap, and a failure leaves the
 *   original contents.
 * - **It writes what it is given and invents nothing.** A row missing a column the table requires is
 *   reported, not defaulted. Filling gaps is the caller's decision to make explicitly.
 *
 * @see PhysicalRestore
 * @see Plugin\Strata\Restore\TruncateRestoreStrategy
 * @see Plugin\Strata\Restore\ShadowSwapStrategy
 */
interface RestoreStrategyInterface
{
	/**
	 * A short lowercase identifier.
	 *
	 * @return string
	 *   For example "truncate" or "shadow_swap".
	 */
	public function id(): string;

	/**
	 * A human label for the confirm form.
	 *
	 * @return string
	 *   The label.
	 */
	public function label(): string;

	/**
	 * What this strategy costs the site while it runs.
	 *
	 * Printed on the confirm form, because "the table is empty for two seconds" and "the table stays
	 * readable throughout" is the difference an operator is choosing between.
	 *
	 * @return string
	 *   One sentence.
	 */
	public function describe(): string;

	/**
	 * Whether this strategy can run on a connection.
	 *
	 * @param Connection $database
	 *   The connection.
	 *
	 * @return bool
	 *   TRUE when the driver supports what the strategy needs.
	 */
	public function isSupported(Connection $database): bool;

	/**
	 * Why the strategy cannot run here.
	 *
	 * @param Connection $database
	 *   The connection.
	 *
	 * @return string|null
	 *   The reason, or NULL when it is supported.
	 */
	public function unsupportedReason(Connection $database): ?string;

	/**
	 * Replaces a table's contents.
	 *
	 * @param Connection $database
	 *   The connection.
	 * @param string $table
	 *   The table to replace.
	 * @param list<array<string, mixed>> $rows
	 *   The rows to leave in it. An empty list empties the table, which is a legitimate restore
	 *   target and is why this does not treat it as a mistake.
	 * @param list<string> $columns
	 *   The columns to write, in order. Given explicitly so a row carrying a column the table no
	 *   longer has is refused rather than silently dropped.
	 *
	 * @return int
	 *   How many rows the table now holds.
	 *
	 * @throws RuntimeException
	 *   When the strategy is unsupported here, or the replacement failed. The table is left as it
	 *   was in either case.
	 */
	public function restore(Connection $database, string $table, array $rows, array $columns): int;
}
