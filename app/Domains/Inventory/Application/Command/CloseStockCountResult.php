<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

/**
 * What finishing a count actually did (Phase 8, stock counts).
 *
 * `adjustmentsRaised` is the number of differences sent for a second person to
 * approve; `linesSkipped` is the number of differences deliberately left alone
 * because the stock moved during the count, or because the person finishing the
 * count may not raise adjustments. Both are reported to the user so a skipped
 * difference is never silent.
 */
final readonly class CloseStockCountResult
{
    public function __construct(
        public bool $completed,
        public int $adjustmentsRaised,
        public int $linesSkipped,
    ) {}
}
