<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A stock transfer was asked for in a shape that cannot be honoured: the same
 * location twice, or two locations in different facilities.
 *
 * A transfer moves stock, it never creates or destroys it, so both sides must
 * live in one facility. Cross-facility movements need a different document and
 * are deliberately not modelled yet.
 */
final class StockTransferNotAllowed extends DomainException
{
    public static function sameLocation(string $locationId): self
    {
        return new self(sprintf(
            'Stock cannot be moved to the location it is already in [%s]: choose a different destination.',
            $locationId,
        ));
    }

    public static function crossFacility(string $fromLocationId, string $toLocationId): self
    {
        return new self(sprintf(
            'Stock cannot be moved between facilities: source [%s] and destination [%s] belong to different facilities.',
            $fromLocationId,
            $toLocationId,
        ));
    }

    public function errorCode(): string
    {
        return 'stock_transfer_not_allowed';
    }
}
