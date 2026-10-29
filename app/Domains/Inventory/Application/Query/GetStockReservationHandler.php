<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\StockReservationSummary;
use App\Domains\Inventory\Domain\StockReservationReader;

final class GetStockReservationHandler
{
    public function __construct(private readonly StockReservationReader $reader) {}

    public function __invoke(GetStockReservation $query): StockReservationSummary
    {
        return $this->reader->summary($query->reservationId);
    }
}
