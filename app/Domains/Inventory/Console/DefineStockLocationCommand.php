<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Console;

use App\Domains\Inventory\Domain\Exception\InventoryCatalogConflict;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Inventory\Domain\StockLocationRepository;
use App\Domains\Inventory\Domain\StockLocationTypeRepository;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * PHASE 9 (increment Inv.1) ops entry point for Stock Location catalog
 * authoring (18-inventory-and-warehousing-domain.md §15–§23, §183; ADR-170
 * D-D). Built-in WAREHOUSE/STORE/QUARANTINE and saved tenant/facility location types are
 * supported. CONSOLE-ONLY — no HTTP command, no permission key ("Inv.0" deferred).
 * Audited (`inventory.stock_location_defined` / `.stock_location_updated`).
 */
final class DefineStockLocationCommand extends Command
{
    protected $signature = 'inventory:define-location
        {action : define|update}
        {--tenant= : tenant uuid}
        {--facility= : facility uuid (required for define)}
        {--id= : location uuid (required for update)}
        {--code= : location code (required for define)}
        {--name= : location name (required for define)}
        {--type= : built-in or saved location type code}
        {--parent= : parent location uuid (same facility)}
        {--department= : department uuid for location-level access (or "none" on update)}
        {--active= : 1|0 — update only}';

    protected $description = 'Define or update a Stock Location catalog entry (ADR-170 D-D).';

    public function handle(
        StockLocationRepository $locations,
        StockLocationTypeRepository $types,
        OrganizationApi $organization,
        AuditLogger $audit,
    ): int {
        $tenantId = (string) $this->option('tenant');
        $action = (string) $this->argument('action');

        if ($tenantId === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }

        if (! in_array($action, ['define', 'update'], true)) {
            $this->error('action must be define|update');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $action, $locations, $types, $organization, $audit): int {
            return $action === 'define'
                ? $this->define($tenantId, $locations, $types, $organization, $audit)
                : $this->update($tenantId, $locations, $types, $organization, $audit);
        });
    }

    private function define(
        string $tenantId,
        StockLocationRepository $locations,
        StockLocationTypeRepository $types,
        OrganizationApi $organization,
        AuditLogger $audit,
    ): int {
        $facilityId = (string) $this->option('facility');
        $code = (string) $this->option('code');
        $name = (string) $this->option('name');
        $type = (string) $this->option('type');

        if ($facilityId === '' || $code === '' || $name === '' || $type === '') {
            $this->error('--facility, --code, --name and --type are required for define.');

            return self::FAILURE;
        }
        if (! $this->isUsableType($tenantId, $facilityId, $type, $types)) {
            $this->error('--type must be WAREHOUSE, STORE, QUARANTINE, or an available saved location type.');

            return self::FAILURE;
        }

        $departmentId = $this->departmentOption($facilityId, $organization);
        if ($this->option('department') !== null && $departmentId === null) {
            return self::FAILURE;
        }

        if ($locations->findActiveByCode($tenantId, $facilityId, strtoupper(trim($code))) !== null) {
            throw InventoryCatalogConflict::locationCode($facilityId, strtoupper(trim($code)));
        }

        $parentId = $this->nullableOption('parent');
        if ($parentId !== null) {
            $parent = $locations->getById($parentId);
            if ((string) $parent->facility_id !== $facilityId) {
                $this->error('--parent must belong to the same facility.');

                return self::FAILURE;
            }
        }

        $id = (string) Str::uuid7();

        $location = StockLocation::define(
            id: $id,
            tenantId: $tenantId,
            facilityId: $facilityId,
            code: $code,
            name: $name,
            locationType: $type,
            parentLocationId: $parentId,
            departmentId: $departmentId,
        );

        $locations->save($location);

        $audit->record(
            action: 'inventory.stock_location_defined',
            targetType: 'inventory_stock_location',
            targetId: $id,
            metadata: [
                'code' => $location->code,
                'location_type' => $location->location_type,
                'department_id' => $location->department_id,
            ],
        );

        $this->info("Defined stock location [{$location->code}] ({$id}) v{$location->version}");

        return self::SUCCESS;
    }

    private function update(
        string $tenantId,
        StockLocationRepository $locations,
        StockLocationTypeRepository $types,
        OrganizationApi $organization,
        AuditLogger $audit,
    ): int {
        $id = (string) $this->option('id');

        if ($id === '') {
            $this->error('--id is required for update.');

            return self::FAILURE;
        }

        $location = $locations->getByIdForUpdate($id);

        $type = $this->nullableOption('type');
        if ($type !== null && ! $this->isUsableType($tenantId, (string) $location->facility_id, $type, $types)) {
            $this->error('--type must be WAREHOUSE, STORE, QUARANTINE, or a saved location type available at the location facility.');

            return self::FAILURE;
        }

        $active = $this->nullableOption('active');
        $departmentId = $this->departmentOption((string) $location->facility_id, $organization, allowClear: true);
        if ($this->option('department') !== null && $departmentId === null) {
            return self::FAILURE;
        }

        $location->revise(
            name: $this->nullableOption('name'),
            locationType: $type,
            parentLocationId: $this->nullableOption('parent'),
            active: $active !== null ? $active === '1' : null,
            departmentId: $departmentId,
        );

        $locations->save($location);

        $audit->record(
            action: 'inventory.stock_location_updated',
            targetType: 'inventory_stock_location',
            targetId: $id,
            metadata: [
                'version' => $location->version,
                'department_id' => $location->department_id,
            ],
        );

        $this->info("Updated stock location [{$id}] -> v{$location->version}");

        return self::SUCCESS;
    }

    private function nullableOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function departmentOption(
        string $facilityId,
        OrganizationApi $organization,
        bool $allowClear = false,
    ): ?string {
        $value = $this->nullableOption('department');
        if ($value === null) {
            return null;
        }
        if ($allowClear && strtolower($value) === 'none') {
            return '';
        }
        if (! $organization->departmentIsActive($facilityId, $value)) {
            $this->error('--department must identify an active department at this facility.');

            return null;
        }

        return $value;
    }

    private function isUsableType(
        string $tenantId,
        string $facilityId,
        string $type,
        StockLocationTypeRepository $types,
    ): bool {
        $code = strtoupper(trim($type));
        if (in_array($code, [
            StockLocation::TYPE_WAREHOUSE,
            StockLocation::TYPE_STORE,
            StockLocation::TYPE_QUARANTINE,
        ], true)) {
            return true;
        }

        $savedType = $types->findAvailableByTenantFacilityAndCode($tenantId, $facilityId, $code);

        return $savedType !== null
            && ($savedType->facility_id === null || (string) $savedType->facility_id === $facilityId);
    }
}
