<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Exception\InventoryCatalogConflict;
use App\Domains\Inventory\Domain\Exception\InventoryCatalogInUse;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Domain\StockLocationRepository;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;

final class SetStockLocationActiveHandler
{
    public function __construct(
        private readonly StockLocationRepository $locations,
        private readonly StockBalanceRepository $balances,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(SetStockLocationActive $command): void
    {
        $location = $this->locations->getByIdForUpdate($command->locationId);
        if ((string) $location->facility_id !== $command->facilityId
            || $this->context->facilityId() !== $command->facilityId) {
            throw NotFound::aggregate(StockLocation::class, $command->locationId);
        }
        if (! $command->active && $this->balances->hasNonZeroBalanceForLocation($command->locationId)) {
            throw InventoryCatalogInUse::locationHasStock($command->locationId);
        }
        if ($command->active && ! (bool) $location->active) {
            $existing = $this->locations->findActiveByCode(
                (string) $location->tenant_id,
                $command->facilityId,
                (string) $location->code,
            );
            if ($existing !== null && (string) $existing->id !== $command->locationId) {
                throw InventoryCatalogConflict::locationCode($command->facilityId, (string) $location->code);
            }
        }

        if ((bool) $location->active === $command->active) {
            return;
        }

        $location->revise(active: $command->active);
        $this->locations->save($location);
    }
}
