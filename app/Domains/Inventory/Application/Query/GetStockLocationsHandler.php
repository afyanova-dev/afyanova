<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\StockLocationSummary;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockLocationReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetStockLocationsHandler
{
    public function __construct(
        private readonly StockLocationReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<StockLocationSummary>
     */
    public function __invoke(GetStockLocations $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetStockLocations dispatched without an authenticated user (IC §6.1).');
        }

        $departmentIds = $this->scope->facilityDepartmentScope($user, $query->facilityId, 'inventory.access');
        if ($departmentIds === []) {
            throw NotFound::aggregate(StockBalance::class, $query->facilityId);
        }

        return $this->reader->listForFacility($query->facilityId, $query->active, $departmentIds);
    }
}
