<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\StockCountSheet;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockCountReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetStockCountSheetHandler
{
    public function __construct(
        private readonly StockCountReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetStockCountSheet $query): StockCountSheet
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetStockCountSheet dispatched without an authenticated user (IC §6.1).');
        }

        $count = $this->reader->summary($query->stockCountId);

        if (! $this->scope->hasFacilityPermission($user, $count->facilityId, 'inventory.access')) {
            throw NotFound::aggregate(StockBalance::class, $query->stockCountId);
        }

        return new StockCountSheet(
            count: $count,
            lines: $this->reader->linesFor($query->stockCountId),
        );
    }
}
