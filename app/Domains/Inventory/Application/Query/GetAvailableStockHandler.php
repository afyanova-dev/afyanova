<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\AvailableStockSummary;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockBalanceReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetAvailableStockHandler
{
    public function __construct(
        private readonly StockBalanceReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetAvailableStock $query): AvailableStockSummary
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetAvailableStock dispatched without an authenticated user (IC §6.1).');
        }

        $departmentIds = $this->scope->facilityDepartmentScope($user, $query->facilityId, 'inventory.access');
        if ($departmentIds === []) {
            throw NotFound::aggregate(StockBalance::class, $query->facilityId);
        }

        return $this->reader->availableForItem($query->itemId, $query->facilityId, $departmentIds);
    }
}
