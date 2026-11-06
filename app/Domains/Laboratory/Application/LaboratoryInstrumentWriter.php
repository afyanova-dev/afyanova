<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application;

use App\Domains\Inventory\Contracts\Dto\InventoryItemRef;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Laboratory\Domain\Model\LabFacilityInstrument;
use App\Domains\Laboratory\Domain\Model\LabFacilityInstrumentMethod;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Domains\Laboratory\Domain\Model\LabTestMethod;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LaboratoryInstrumentWriter
{
    public function __construct(
        private readonly InventoryApi $inventory,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     identifier: string, name: string, manufacturer: ?string, model: ?string,
     *     serial_number: ?string, inventory_item_id: ?string, supported_method_ids: list<string>
     * }  $data
     */
    public function create(array $data, string $actorId): void
    {
        $tenantId = $this->context->tenantId();
        $facilityId = $this->context->requireFacilityId();

        DB::transaction(function () use ($data, $actorId, $tenantId, $facilityId): void {
            $inventoryItem = $this->equipmentItem($data['inventory_item_id']);
            $methods = $this->activeMethods($data['supported_method_ids']);
            $instrument = LabFacilityInstrument::register([
                'tenantId' => $tenantId,
                'facilityId' => $facilityId,
                'identifier' => $data['identifier'],
                'name' => $data['name'],
                'manufacturer' => $data['manufacturer'],
                'model' => $data['model'],
                'serialNumber' => $data['serial_number'],
                'inventoryItemId' => $inventoryItem?->itemId,
                'inventoryCodeSnapshot' => $inventoryItem?->code,
                'inventoryNameSnapshot' => $inventoryItem?->name,
            ]);
            $instrument->save();
            $this->saveSupportedMethods($instrument, $methods);

            $this->audit->record(
                action: 'laboratory.instrument_registered',
                actorId: $actorId,
                targetType: 'lab_facility_instrument',
                targetId: (string) $instrument->id,
                metadata: [
                    'identifier' => $instrument->identifier,
                    'status' => $instrument->status,
                    'supported_method_count' => count($methods),
                    'inventory_item_linked' => $inventoryItem !== null,
                ],
            );
        });
    }

    /**
     * @param  array{
     *     identifier: string, name: string, manufacturer: ?string, model: ?string,
     *     serial_number: ?string, inventory_item_id: ?string, status: string,
     *     supported_method_ids: list<string>
     * }  $data
     */
    public function update(string $instrumentId, array $data, string $actorId): void
    {
        $facilityId = $this->context->requireFacilityId();

        DB::transaction(function () use ($instrumentId, $data, $actorId, $facilityId): void {
            $instrument = LabFacilityInstrument::query()
                ->where('facility_id', $facilityId)
                ->whereKey($instrumentId)
                ->lockForUpdate()
                ->firstOrFail();
            $inventoryItem = $this->equipmentItem($data['inventory_item_id']);
            $methods = $this->activeMethods($data['supported_method_ids']);
            if ($instrument->status === LabFacilityInstrument::STATUS_DECOMMISSIONED
                && $data['status'] !== LabFacilityInstrument::STATUS_DECOMMISSIONED) {
                throw ValidationException::withMessages([
                    'status' => 'A decommissioned instrument cannot be returned to service.',
                ]);
            }
            $instrument->revise([
                'identifier' => $data['identifier'],
                'name' => $data['name'],
                'manufacturer' => $data['manufacturer'],
                'model' => $data['model'],
                'serialNumber' => $data['serial_number'],
                'inventoryItemId' => $inventoryItem?->itemId,
                'inventoryCodeSnapshot' => $inventoryItem?->code,
                'inventoryNameSnapshot' => $inventoryItem?->name,
                'status' => $data['status'],
            ]);
            $instrument->save();
            $this->saveSupportedMethods($instrument, $methods);

            $this->audit->record(
                action: 'laboratory.instrument_updated',
                actorId: $actorId,
                targetType: 'lab_facility_instrument',
                targetId: $instrumentId,
                metadata: [
                    'identifier' => $instrument->identifier,
                    'status' => $instrument->status,
                    'version' => $instrument->version,
                    'supported_method_count' => count($methods),
                    'inventory_item_linked' => $inventoryItem !== null,
                ],
            );
        });
    }

    private function equipmentItem(?string $inventoryItemId): ?InventoryItemRef
    {
        if ($inventoryItemId === null || $inventoryItemId === '') {
            return null;
        }

        $item = $this->inventory->getItem($inventoryItemId);

        if ($item === null || ! $item->active || $item->itemType !== 'EQUIPMENT') {
            throw ValidationException::withMessages([
                'inventory_item_id' => 'Select an active Inventory equipment item, or leave this field blank.',
            ]);
        }

        return $item;
    }

    /**
     * @param  list<string>  $methodIds
     * @return Collection<int, LabTestMethod>
     */
    private function activeMethods(array $methodIds): Collection
    {
        if (count($methodIds) !== count(array_unique($methodIds))) {
            throw ValidationException::withMessages([
                'supported_method_ids' => 'A test method can only be selected once.',
            ]);
        }

        if ($methodIds === []) {
            return new Collection;
        }

        $methods = LabTestMethod::query()
            ->join('lab_test_definitions', 'lab_test_definitions.id', '=', 'lab_test_methods.test_definition_id')
            ->whereIn('lab_test_methods.id', $methodIds)
            ->where('lab_test_methods.active', true)
            ->where('lab_test_definitions.active', true)
            ->where('lab_test_definitions.availability_status', LabTestDefinition::AVAILABILITY_AVAILABLE)
            ->select('lab_test_methods.*')
            ->orderBy('lab_test_methods.id')
            ->get();

        if ($methods->count() !== count(array_unique($methodIds))) {
            throw ValidationException::withMessages([
                'supported_method_ids' => 'Choose only active methods for available laboratory tests in this tenant.',
            ]);
        }

        return $methods;
    }

    /**
     * @param  Collection<int, LabTestMethod>  $methods
     */
    private function saveSupportedMethods(LabFacilityInstrument $instrument, Collection $methods): void
    {
        foreach ($methods as $method) {
            LabFacilityInstrumentMethod::query()->create([
                'tenant_id' => $instrument->tenant_id,
                'instrument_id' => $instrument->id,
                'instrument_version' => $instrument->version,
                'method_id' => $method->id,
                'method_version' => $method->version,
                'method_code_snapshot' => $method->code,
                'method_name_snapshot' => $method->name,
            ]);
        }
    }
}
