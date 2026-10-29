<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one stock reservation (§112–§116, §257; ADR-180 D-G). `view` gates on
 * `inventory.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: StockReservation::class)]
final readonly class GetStockReservation
{
    public function __construct(public string $reservationId)
    {
        if (! Str::isUuid($reservationId)) {
            throw new InvalidArgumentException('GetStockReservation::$reservationId must be a UUID (IC §3).');
        }
    }
}
