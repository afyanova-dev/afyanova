<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read the stock reservations at a facility (§112–§116, §150; ADR-180 D-G),
 * optionally filtered by item / location / status / consumer ref. `view` gates
 * on `inventory.access`; the handler re-checks the acting user's scope at the
 * facility (the `GetStockAdjustments` pattern).
 */
#[ProtectedQuery(ability: 'view', subjectType: StockReservation::class)]
final readonly class SearchStockReservations
{
    public function __construct(
        public string $facilityId,
        public ?string $itemId = null,
        public ?string $locationId = null,
        public ?string $status = null,
        public ?string $consumerRef = null,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('SearchStockReservations::$facilityId must be a UUID (IC §3).');
        }
        foreach (['itemId' => $itemId, 'locationId' => $locationId] as $name => $value) {
            if ($value !== null && ! Str::isUuid($value)) {
                throw new InvalidArgumentException("SearchStockReservations::\${$name} must be a UUID or null (IC §3).");
            }
        }
    }
}
