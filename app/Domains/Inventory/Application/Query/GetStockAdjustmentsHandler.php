<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\StockAdjustmentSummary;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\StockAdjustmentReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetStockAdjustmentsHandler
{
    public function __construct(
        private readonly StockAdjustmentReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<StockAdjustmentSummary>
     */
    public function __invoke(GetStockAdjustments $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetStockAdjustments dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermission($user, $query->facilityId, 'inventory.access')) {
            throw NotFound::aggregate(StockAdjustment::class, $query->facilityId);
        }

        return $this->reader->listForFacility($query->facilityId, $query->status);
    }
}
