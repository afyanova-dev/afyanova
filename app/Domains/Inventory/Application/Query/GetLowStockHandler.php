<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\LowStockItemSummary;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockBalanceReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetLowStockHandler
{
    public function __construct(
        private readonly StockBalanceReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<LowStockItemSummary>
     */
    public function __invoke(GetLowStock $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetLowStock dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermission($user, $query->facilityId, 'inventory.access')) {
            throw NotFound::aggregate(StockBalance::class, $query->facilityId);
        }

        return $this->reader->lowStockForFacility($query->facilityId);
    }
}
