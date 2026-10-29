<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A stock count was asked for in a shape that cannot be honoured — a second
 * count already open at the location, a change to a count that is no longer
 * open, a line already on the sheet, or a batch-tracked item named without its
 * batch.
 *
 * A count records what is on the shelf; it never edits a balance, so an
 * invalid count is refused outright rather than silently corrected.
 */
final class StockCountNotAllowed extends DomainException
{
    public static function alreadyInProgress(string $locationId): self
    {
        return new self(sprintf(
            'A stock count is already open at this location [%s]: finish or discard it before starting another.',
            $locationId,
        ));
    }

    public static function notOpen(string $stockCountId, string $status): self
    {
        return new self(sprintf(
            'Stock count [%s] is %s, so it can no longer be changed.',
            $stockCountId,
            strtolower($status),
        ));
    }

    public static function lineAlreadyOnSheet(string $itemId): self
    {
        return new self(sprintf(
            'Item [%s] is already on this count sheet.',
            $itemId,
        ));
    }

    public function errorCode(): string
    {
        return 'stock_count_not_allowed';
    }
}
