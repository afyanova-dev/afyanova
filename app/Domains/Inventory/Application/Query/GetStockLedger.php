<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read the stock-movement history for one (item, location) with running
 * balances and a reconciliation drift (§84 / §214 / §253; ADR-170 D-J / D-M).
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetStockLedger
{
    public function __construct(
        public string $itemId,
        public string $locationId,
    ) {
        foreach (['itemId' => $itemId, 'locationId' => $locationId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("GetStockLedger::\${$name} must be a UUID (IC §3).");
            }
        }
    }
}
