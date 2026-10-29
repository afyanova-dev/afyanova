<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\StockCountSummary;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockCountReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetStockCountsHandler
{
    public function __construct(
        private readonly StockCountReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<StockCountSummary>
     */
    public function __invoke(GetStockCounts $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetStockCounts dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermission($user, $query->facilityId, 'inventory.access')) {
            throw NotFound::aggregate(StockBalance::class, $query->facilityId);
        }

        return $this->reader->recentForFacility($query->facilityId, $query->limit);
    }
}
