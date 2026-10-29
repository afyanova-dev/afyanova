<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one Item Master catalog entry (ADR-170 D-M). Protected at the coarse
 * `inventory.access` key (the Rad.1 catalog-read-through-the-balance-policy
 * precedent).
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetItem
{
    public function __construct(public string $itemId)
    {
        if (! Str::isUuid($itemId)) {
            throw new InvalidArgumentException('GetItem::$itemId must be a UUID (IC §3).');
        }
    }
}
