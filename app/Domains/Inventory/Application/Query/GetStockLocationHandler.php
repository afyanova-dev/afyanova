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

final class GetStockLocationHandler
{
    public function __construct(
        private readonly StockLocationReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetStockLocation $query): StockLocationSummary
    {
        $summary = $this->reader->summary($query->locationId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetStockLocation dispatched without an authenticated user (IC §6.1).');
        }

        if (! $this->scope->hasFacilityLocationPermission(
            $user,
            $summary->facilityId,
            'inventory.access',
            $summary->departmentId,
        )) {
            throw NotFound::aggregate(StockBalance::class, $query->locationId);
        }

        return $summary;
    }
}
