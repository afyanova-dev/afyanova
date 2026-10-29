<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read available stock for one item across a facility's locations
 * (§29 / §30 / §327; ADR-170 D-M — facility-scoped, per-item, per-location
 * breakdown).
 */
#[ProtectedQuery(ability: 'view', subjectType: StockBalance::class)]
final readonly class GetAvailableStock
{
    public function __construct(
        public string $itemId,
        public string $facilityId,
    ) {
        foreach (['itemId' => $itemId, 'facilityId' => $facilityId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("GetAvailableStock::\${$name} must be a UUID (IC §3).");
            }
        }
    }
}
