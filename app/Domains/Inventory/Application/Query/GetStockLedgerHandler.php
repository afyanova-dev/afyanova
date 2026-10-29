<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\StockLedgerSummary;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockLedgerReader;
use App\Domains\Inventory\Domain\StockLocationReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetStockLedgerHandler
{
    public function __construct(
        private readonly StockLedgerReader $reader,
        private readonly StockLocationReader $locations,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetStockLedger $query): StockLedgerSummary
    {
        $location = $this->locations->summary($query->locationId); // NotFound if unknown

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetStockLedger dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityLocationPermission(
            $user,
            $location->facilityId,
            'inventory.access',
            $location->departmentId,
        )) {
            throw NotFound::aggregate(StockBalance::class, $query->locationId);
        }

        return $this->reader->history($query->itemId, $query->locationId);
    }
}
