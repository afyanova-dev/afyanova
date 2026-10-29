<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Exception\InventoryCatalogConflict;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Inventory\Domain\StockLocationRepository;
use App\Domains\Inventory\Domain\StockLocationTypeRepository;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;

final class DefineStockLocationHandler
{
    public function __construct(
        private readonly StockLocationRepository $locations,
        private readonly StockLocationTypeRepository $locationTypes,
        private readonly TenantContext $context,
        private readonly OrganizationApi $organization,
    ) {}

    public function __invoke(DefineStockLocation $command): void
    {
        $tenantId = $this->context->tenantId();
        $facilityId = $this->context->facilityId();
        if ($facilityId === null || $facilityId !== $command->facilityId) {
            throw NotFound::aggregate(StockLocation::class, $command->facilityId);
        }

        $typeCode = strtoupper(trim($command->locationType));
        if (! in_array($typeCode, [
            StockLocation::TYPE_WAREHOUSE,
            StockLocation::TYPE_STORE,
            StockLocation::TYPE_QUARANTINE,
        ], true)) {
            $type = $this->locationTypes->findAvailableByTenantFacilityAndCode($tenantId, $facilityId, $typeCode);
            if ($type === null
                || ($type->facility_id !== null && (string) $type->facility_id !== $facilityId)) {
                throw NotFound::aggregate(StockLocation::class, $typeCode);
            }
        }

        $code = strtoupper(trim($command->code));
        if ($this->locations->findActiveByCode($tenantId, $facilityId, $code) !== null) {
            throw InventoryCatalogConflict::locationCode($facilityId, $code);
        }

        if ($command->parentLocationId !== null) {
            $parent = $this->locations->getById($command->parentLocationId);
            if ((string) $parent->tenant_id !== $tenantId || (string) $parent->facility_id !== $facilityId) {
                throw NotFound::aggregate(StockLocation::class, $command->parentLocationId);
            }
        }
        if ($command->departmentId !== null
            && ! $this->organization->departmentIsActive($facilityId, $command->departmentId)) {
            throw NotFound::aggregate(StockLocation::class, $command->departmentId);
        }

        $location = StockLocation::define(
            id: $command->locationId,
            tenantId: $tenantId,
            facilityId: $facilityId,
            code: $command->code,
            name: $command->name,
            locationType: $command->locationType,
            parentLocationId: $command->parentLocationId,
            departmentId: $command->departmentId,
        );

        $this->locations->save($location);
    }
}
