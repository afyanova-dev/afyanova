<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\StockReservationSummary;
use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Domains\Inventory\Domain\StockReservationReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class SearchStockReservationsHandler
{
    public function __construct(
        private readonly StockReservationReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<StockReservationSummary>
     */
    public function __invoke(SearchStockReservations $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('SearchStockReservations dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermission($user, $query->facilityId, 'inventory.access')) {
            throw NotFound::aggregate(StockReservation::class, $query->facilityId);
        }

        return $this->reader->search(
            $query->facilityId,
            $query->itemId,
            $query->locationId,
            $query->status,
            $query->consumerRef,
        );
    }
}
