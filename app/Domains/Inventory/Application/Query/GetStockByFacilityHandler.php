<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockBalanceReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetStockByFacilityHandler
{
    public function __construct(
        private readonly StockBalanceReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * Returns a sorted page of per-(item, location, batch) balances.
     */
    public function __invoke(GetStockByFacility $query): LengthAwarePaginator
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetStockByFacility dispatched without an authenticated user (IC §6.1).');
        }
        $departmentIds = $this->scope->facilityDepartmentScope($user, $query->facilityId, 'inventory.access');
        if ($departmentIds === []) {
            throw NotFound::aggregate(StockBalance::class, $query->facilityId);
        }

        return $this->reader->forFacility(
            $query->facilityId,
            $query->page,
            $query->perPage,
            $query->sortBy,
            $query->sortDirection,
            $query->search,
            $query->locationId,
            $departmentIds,
        );
    }
}
