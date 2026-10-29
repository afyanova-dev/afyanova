<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\StockTransferSummary;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockTransferReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetStockTransfersHandler
{
    public function __construct(
        private readonly StockTransferReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * @return list<StockTransferSummary>
     */
    public function __invoke(GetStockTransfers $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetStockTransfers dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityPermission($user, $query->facilityId, 'inventory.access')) {
            throw NotFound::aggregate(StockBalance::class, $query->facilityId);
        }

        return $this->reader->recentForFacility($query->facilityId, $query->limit);
    }
}
