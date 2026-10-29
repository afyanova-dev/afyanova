<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\StockLocationTypeSummary;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\StockLocationTypeReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GetStockLocationTypesHandler
{
    public function __construct(
        private readonly StockLocationTypeReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    /** @return list<StockLocationTypeSummary> */
    public function __invoke(GetStockLocationTypes $query): array
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetStockLocationTypes dispatched without an authenticated user.');
        }

        $canRead = $query->facilityId === null
            ? $user->can('inventory.access')
            : $this->scope->hasFacilityPermission($user, $query->facilityId, 'inventory.access');

        if (! $canRead) {
            throw NotFound::aggregate(StockBalance::class, $query->facilityId ?? (string) $user->tenant_id);
        }

        return $this->reader->listForTenantAndFacility(
            (string) $user->tenant_id,
            $query->facilityId,
        );
    }
}
