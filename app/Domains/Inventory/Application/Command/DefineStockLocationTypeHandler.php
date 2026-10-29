<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Domain\Exception\InventoryCatalogConflict;
use App\Domains\Inventory\Domain\Model\StockLocationType;
use App\Domains\Inventory\Domain\StockLocationTypeRepository;
use App\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class DefineStockLocationTypeHandler
{
    public function __construct(
        private readonly StockLocationTypeRepository $types,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(DefineStockLocationType $command): void
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('Location type creation requires an authenticated user.');
        }

        $tenantId = $this->context->tenantId();
        $facilityId = $this->context->facilityId();
        $tenantWide = $this->context->isGeneral();
        if (! $tenantWide && ($facilityId === null || $facilityId === '')) {
            throw new RuntimeException('Location type creation requires General or an active facility context.');
        }

        $code = strtoupper(trim($command->code));
        $scopeFacilityId = $tenantWide ? null : $facilityId;
        if ($command->locationTypeFacilityId !== $scopeFacilityId) {
            throw new AuthorizationException('Location type scope does not match the working context.');
        }

        if (in_array($code, ['WAREHOUSE', 'STORE', 'QUARANTINE'], true)
            || $this->types->findAvailableByTenantFacilityAndCode(
                $tenantId,
                $scopeFacilityId ?? '',
                $code,
                includeAllFacilities: $tenantWide,
            ) !== null) {
            throw InventoryCatalogConflict::locationTypeCode($code);
        }

        $type = StockLocationType::define(
            id: $command->typeId,
            tenantId: $tenantId,
            facilityId: $scopeFacilityId,
            code: $code,
            name: $command->name,
            createdBy: $command->actorId,
        );

        $this->types->save($type);
    }
}
