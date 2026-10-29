<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read the materialised stock balance for one (item, location) — one row per
 * batch, with all buckets and the computed available quantity (§29 / §30 /
 * §327; ADR-170 D-M).
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetStockBalance
{
    public function __construct(
        public string $itemId,
        public string $locationId,
    ) {
        foreach (['itemId' => $itemId, 'locationId' => $locationId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("GetStockBalance::\${$name} must be a UUID (IC §3).");
            }
        }
    }
}
