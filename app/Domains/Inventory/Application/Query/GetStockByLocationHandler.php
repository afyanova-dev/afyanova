<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockBalanceReader;
use App\Domains\Inventory\Domain\StockLocationReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetStockByLocationHandler
{
    public function __construct(
        private readonly StockBalanceReader $reader,
        private readonly StockLocationReader $locations,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * Returns a sorted page of per-item totals and the included batch balances.
     */
    public function __invoke(GetStockByLocation $query): LengthAwarePaginator
    {
        $location = $this->locations->summary($query->locationId);
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetStockByLocation dispatched without an authenticated user (IC §6.1).');
        }
        if (! $this->scope->hasFacilityLocationPermission(
            $user,
            $location->facilityId,
            'inventory.access',
            $location->departmentId,
        )) {
            throw NotFound::aggregate(StockBalance::class, $query->locationId);
        }

        return $this->reader->forLocation(
            $query->locationId,
            $query->page,
            $query->perPage,
            $query->sortBy,
            $query->sortDirection,
            $query->search,
        );
    }
}
