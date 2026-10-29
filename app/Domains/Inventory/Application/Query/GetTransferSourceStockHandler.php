<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\LocationItemStockSummary;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockBalanceReader;
use App\Domains\Inventory\Domain\StockLocationReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetTransferSourceStockHandler
{
    public function __construct(
        private readonly StockBalanceReader $reader,
        private readonly StockLocationReader $locations,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<LocationItemStockSummary>
     */
    public function __invoke(GetTransferSourceStock $query): array
    {
        $location = $this->locations->summary($query->locationId);
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetTransferSourceStock dispatched without an authenticated user (IC §6.1).');
        }
        if (! $this->scope->hasFacilityLocationPermission(
            $user,
            $location->facilityId,
            'inventory.access',
            $location->departmentId,
        )) {
            throw NotFound::aggregate(StockBalance::class, $query->locationId);
        }

        return $this->reader->stockAtLocation($query->locationId);
    }
}
