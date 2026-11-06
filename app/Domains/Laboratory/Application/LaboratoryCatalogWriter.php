<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application;

use App\Domains\Inventory\Contracts\Dto\InventoryItemRef;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Laboratory\Domain\Exception\LabPanelAlreadyExists;
use App\Domains\Laboratory\Domain\Exception\LabTestDefinitionAlreadyExists;
use App\Domains\Laboratory\Domain\LabPanelRepository;
use App\Domains\Laboratory\Domain\LabTestDefinitionRepository;
use App\Domains\Laboratory\Domain\Model\LabMethodInventoryRequirement;
use App\Domains\Laboratory\Domain\Model\LabPanel;
use App\Domains\Laboratory\Domain\Model\LabPanelMember;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Domains\Laboratory\Domain\Model\LabTestMethod;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class LaboratoryCatalogWriter
{
    public function __construct(
        private readonly LabTestDefinitionRepository $definitions,
        private readonly LabPanelRepository $panels,
        private readonly InventoryApi $inventory,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     code: string, name: string, category: string, description?: string|null,
     *     specimen_type: string, container_type: string, parameters: list<array<string, mixed>>,
     *     required_capability_code?: string|null, availability_status: string,
     *     turnaround_target_minutes?: int|null
     * }  $data
     */
    public function createTest(array $data, string $actorId): void
    {
        $tenantId = $this->context->tenantId();

        DB::transaction(function () use ($data, $actorId, $tenantId): void {
            if ($this->definitions->findActiveByCode($tenantId, $data['code']) !== null) {
                throw LabTestDefinitionAlreadyExists::forCode($tenantId, $data['code']);
            }

            $definition = LabTestDefinition::define(
                id: (string) Str::uuid7(),
                tenantId: $tenantId,
                code: $data['code'],
                name: $data['name'],
                category: $data['category'],
                description: $data['description'] ?? null,
                specimenType: $data['specimen_type'],
                containerType: $data['container_type'],
                parameters: $data['parameters'],
                requiredCapabilityCode: $data['required_capability_code'] ?? null,
                availabilityStatus: $data['availability_status'],
                turnaroundTargetMinutes: $data['turnaround_target_minutes'] ?? null,
            );

            $this->definitions->save($definition);
            $this->audit->record(
                action: 'laboratory.test_defined',
                actorId: $actorId,
                targetType: 'lab_test_definition',
                targetId: (string) $definition->id,
                metadata: ['code' => $definition->code, 'version' => 1],
            );
        });
    }

    /**
     * @param  array{
     *     name: string, category: string, description?: string|null,
     *     specimen_type: string, container_type: string, parameters: list<array<string, mixed>>,
     *     required_capability_code?: string|null, availability_status: string,
     *     turnaround_target_minutes?: int|null, active?: bool
     * }  $data
     */
    public function updateTest(string $definitionId, array $data, string $actorId): void
    {
        DB::transaction(function () use ($definitionId, $data, $actorId): void {
            $definition = $this->definitions->getByIdForUpdate($definitionId);
            $definition->revise(
                name: $data['name'],
                category: $data['category'],
                description: $data['description'] ?? '',
                specimenType: $data['specimen_type'],
                containerType: $data['container_type'],
                parameters: $data['parameters'],
                requiredCapabilityCode: $data['required_capability_code'] ?? '',
                availabilityStatus: $data['availability_status'],
                turnaroundTargetMinutes: $data['turnaround_target_minutes'] ?? 0,
                active: $data['active'] ?? null,
            );
            $definition->turnaround_target_minutes = $data['turnaround_target_minutes'] ?? null;

            $this->definitions->save($definition);
            $this->audit->record(
                action: 'laboratory.test_updated',
                actorId: $actorId,
                targetType: 'lab_test_definition',
                targetId: $definitionId,
                metadata: ['code' => $definition->code, 'version' => $definition->version],
            );
        });
    }

    /**
     * @param  array{code: string, name: string, category?: string|null, description?: string|null, member_test_ids: list<string>}  $data
     */
    public function createPanel(array $data, string $actorId): void
    {
        $tenantId = $this->context->tenantId();

        DB::transaction(function () use ($tenantId, $data, $actorId): void {
            if ($this->panels->findActiveByCode($tenantId, $data['code']) !== null) {
                throw LabPanelAlreadyExists::forCode($tenantId, $data['code']);
            }

            $panel = LabPanel::define(
                id: (string) Str::uuid7(),
                tenantId: $tenantId,
                code: $data['code'],
                name: $data['name'],
                category: $data['category'] ?? null,
                description: $data['description'] ?? null,
            );

            $this->panels->save($panel);
            $this->replacePanelMembers($panel, $data['member_test_ids']);
            $this->audit->record(
                action: 'laboratory.panel_defined',
                actorId: $actorId,
                targetType: 'lab_panel',
                targetId: (string) $panel->id,
                metadata: ['code' => $panel->code, 'version' => 1],
            );
        });
    }

    /**
     * @param  array{name: string, category?: string|null, description?: string|null, member_test_ids: list<string>, active?: bool}  $data
     */
    public function updatePanel(string $panelId, array $data, string $actorId): void
    {
        DB::transaction(function () use ($panelId, $data, $actorId): void {
            $panel = $this->panels->getByIdForUpdate($panelId);
            $panel->revise(
                name: $data['name'],
                category: $data['category'] ?? '',
                description: $data['description'] ?? '',
                active: $data['active'] ?? null,
            );

            $this->panels->save($panel);
            $this->replacePanelMembers($panel, $data['member_test_ids']);
            $this->audit->record(
                action: 'laboratory.panel_updated',
                actorId: $actorId,
                targetType: 'lab_panel',
                targetId: $panelId,
                metadata: ['code' => $panel->code, 'version' => $panel->version],
            );
        });
    }

    /**
     * @param  array{
     *     code: string, name: string, description?: string|null,
     *     specimen_type?: string|null, container_type?: string|null,
     *     requirements: list<array{
     *         inventory_item_id: string, role: string, required: bool|string|int,
     *         usage_scope: string, quantity: int|float|string
     *     }>
     * }  $data
     */
    public function createTestMethod(string $definitionId, array $data, string $actorId): void
    {
        $tenantId = $this->context->tenantId();

        DB::transaction(function () use ($definitionId, $data, $actorId, $tenantId): void {
            $definition = LabTestDefinition::query()->whereKey($definitionId)->firstOrFail();

            if (! $definition->active) {
                throw ValidationException::withMessages([
                    'definition' => 'Methods can only be configured for active test definitions.',
                ]);
            }

            $method = LabTestMethod::query()
                ->where('test_definition_id', $definitionId)
                ->where('code', trim($data['code']))
                ->where('active', true)
                ->first();

            if ($method !== null) {
                throw ValidationException::withMessages([
                    'code' => 'An active method with this code already exists for the test.',
                ]);
            }

            $method = LabTestMethod::query()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'test_definition_id' => $definitionId,
                'code' => trim($data['code']),
                'name' => trim($data['name']),
                'description' => $this->blankToNull($data['description'] ?? null),
                'specimen_type' => $this->blankToNull($data['specimen_type'] ?? null),
                'container_type' => $this->blankToNull($data['container_type'] ?? null),
                'active' => true,
                'version' => 1,
            ]);

            $this->saveRequirementSnapshot($method, $data['requirements'], 1);
            $this->audit->record(
                action: 'laboratory.test_method_defined',
                actorId: $actorId,
                targetType: 'lab_test_method',
                targetId: (string) $method->id,
                metadata: [
                    'test_definition_id' => $definitionId,
                    'code' => $method->code,
                    'version' => 1,
                    'requirement_count' => count($data['requirements']),
                ],
            );
        });
    }

    /**
     * @param  array{
     *     name: string, description?: string|null,
     *     specimen_type?: string|null, container_type?: string|null,
     *     active: bool|string|int,
     *     requirements: list<array{
     *         inventory_item_id: string, role: string, required: bool|string|int,
     *         usage_scope: string, quantity: int|float|string
     *     }>
     * }  $data
     */
    public function updateTestMethod(string $definitionId, string $methodId, array $data, string $actorId): void
    {
        DB::transaction(function () use ($definitionId, $methodId, $data, $actorId): void {
            $method = LabTestMethod::query()
                ->whereKey($methodId)
                ->where('test_definition_id', $definitionId)
                ->lockForUpdate()
                ->firstOrFail();
            $isActive = (bool) $data['active'];

            if ($isActive && ! $method->active && LabTestMethod::query()
                ->where('test_definition_id', $definitionId)
                ->where('code', $method->code)
                ->where('active', true)
                ->where('id', '<>', $methodId)
                ->exists()) {
                throw ValidationException::withMessages([
                    'active' => 'An active method with this code already exists for the test.',
                ]);
            }

            $method->revise(
                name: $data['name'],
                description: $data['description'] ?? null,
                specimenType: $data['specimen_type'] ?? null,
                containerType: $data['container_type'] ?? null,
                active: $isActive,
            );
            $method->save();

            $this->saveRequirementSnapshot($method, $data['requirements'], (int) $method->version);
            $this->audit->record(
                action: 'laboratory.test_method_updated',
                actorId: $actorId,
                targetType: 'lab_test_method',
                targetId: $methodId,
                metadata: [
                    'test_definition_id' => $definitionId,
                    'code' => $method->code,
                    'version' => $method->version,
                    'requirement_count' => count($data['requirements']),
                ],
            );
        });
    }

    /**
     * @param  list<array{
     *     inventory_item_id: string, role: string, required: bool|string|int,
     *     usage_scope: string, quantity: int|float|string
     * }>  $requirements
     */
    private function saveRequirementSnapshot(LabTestMethod $method, array $requirements, int $methodVersion): void
    {
        foreach ($requirements as $index => $requirement) {
            $item = $this->usableInventoryItem($requirement['inventory_item_id'], (string) $requirement['role'], $index);

            LabMethodInventoryRequirement::query()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $method->tenant_id,
                'method_id' => $method->id,
                'method_version' => $methodVersion,
                'inventory_item_id' => $item->itemId,
                'item_code_snapshot' => $item->code,
                'item_name_snapshot' => $item->name,
                'item_uom_snapshot' => $item->uom,
                'item_type_snapshot' => $item->itemType,
                'item_category_snapshot' => $item->category,
                'requirement_role' => $requirement['role'],
                'required' => (bool) $requirement['required'],
                'usage_scope' => $requirement['usage_scope'],
                'quantity' => (string) $requirement['quantity'],
            ]);
        }
    }

    private function usableInventoryItem(string $itemId, string $role, int $index): InventoryItemRef
    {
        $item = $this->inventory->getItem($itemId);

        if ($item === null || ! $item->active || ! in_array($item->itemType, ['SUPPLY', 'CONSUMABLE'], true)) {
            throw ValidationException::withMessages([
                "requirements.{$index}.inventory_item_id" => 'Select an active Supply or Consumable item from this tenant.',
            ]);
        }

        if ($role === 'REAGENT' && $item->category !== 'DIAGNOSTIC_REAGENT') {
            throw ValidationException::withMessages([
                "requirements.{$index}.inventory_item_id" => 'Reagent requirements must use an item categorized as a diagnostic reagent.',
            ]);
        }

        return $item;
    }

    private function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string>  $testDefinitionIds
     */
    private function replacePanelMembers(LabPanel $panel, array $testDefinitionIds): void
    {
        $validMemberCount = LabTestDefinition::query()
            ->where('tenant_id', $panel->tenant_id)
            ->whereIn('id', $testDefinitionIds)
            ->where('active', true)
            ->where('availability_status', LabTestDefinition::AVAILABILITY_AVAILABLE)
            ->count();

        if ($validMemberCount !== count($testDefinitionIds)) {
            throw ValidationException::withMessages([
                'member_test_ids' => 'Panels may only contain active, available tests from this tenant.',
            ]);
        }

        LabPanelMember::query()->where('panel_id', $panel->id)->delete();

        foreach ($testDefinitionIds as $index => $definitionId) {
            LabPanelMember::query()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $panel->tenant_id,
                'panel_id' => $panel->id,
                'test_definition_id' => $definitionId,
                'sequence' => $index + 1,
                'version' => 0,
            ]);
        }
    }
}
