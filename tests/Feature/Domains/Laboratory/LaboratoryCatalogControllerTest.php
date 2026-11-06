<?php

declare(strict_types=1);

use App\Domains\Identity\Contracts\FacilityAdminRoleDefinition;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Laboratory\Domain\Model\LabFacilityInstrument;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Domains\Laboratory\Domain\Model\LabTestMethod;
use App\Domains\Organization\Domain\Model\Facility;
use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../Support/laboratory.php';

uses(RefreshDatabase::class);

function laboratoryCatalogAdminFixture(bool $grantEditor = false): array
{
    $fixture = laboratoryFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $organizationId = (string) $fixture['facility']->organization_id;
    $userId = (string) $fixture['user']->id;

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $organizationId, $userId, $grantEditor): void {
        $identity = app(IdentityApi::class);
        $adminRoleId = $identity->provisionTenantAdmin($tenantId, $userId, 'Catalog editor test setup');
        $technicianRoleId = $identity->provisionFacilityRole($tenantId, $userId, $facilityId, 'laboratory_technician');

        DB::table('idn_access_assignments')->insert([
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $adminRoleId,
                'organization_id' => $organizationId,
                'facility_id' => null,
                'department_id' => null,
                'unit_id' => null,
                'status' => 'ACTIVE',
                'effective_from' => null,
                'effective_to' => null,
                'is_primary' => false,
                'granted_by' => $userId,
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role_id' => $technicianRoleId,
                'organization_id' => null,
                'facility_id' => $facilityId,
                'department_id' => null,
                'unit_id' => null,
                'status' => 'ACTIVE',
                'effective_from' => null,
                'effective_to' => null,
                'is_primary' => false,
                'granted_by' => $userId,
                'version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        if ($grantEditor) {
            $identity->grantLaboratoryCatalogEditor($tenantId, $userId, $userId);
        }
    });

    return $fixture;
}

function laboratoryCatalogTestPayload(array $overrides = []): array
{
    return array_replace([
        'code' => 'FBC',
        'name' => 'Full Blood Count',
        'category' => 'HAEMATOLOGY',
        'description' => 'Complete blood count.',
        'specimen_type' => 'whole_blood',
        'container_type' => 'EDTA_TUBE',
        'parameters' => [
            [
                'code' => 'HB',
                'name' => 'Haemoglobin',
                'value_type' => 'NUMERIC',
                'unit' => 'g/dL',
                'reference_ranges' => ['low' => 12, 'high' => 16, 'text' => '', 'source' => ''],
                'coded_values' => [],
            ],
            [
                'code' => 'MALARIA',
                'name' => 'Malaria parasite',
                'value_type' => 'CODED',
                'unit' => '',
                'reference_ranges' => ['low' => null, 'high' => null, 'text' => '', 'source' => ''],
                'coded_values' => [
                    ['code' => 'POS', 'name' => 'Positive'],
                    ['code' => 'NEG', 'name' => 'Negative'],
                ],
            ],
        ],
        'required_capability_code' => '',
        'availability_status' => 'AVAILABLE',
        'turnaround_target_minutes' => 45,
    ], $overrides);
}

it('lets only a selected active laboratory technician manage the tenant catalog', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $userId = (string) $user->id;
    $session = ['active_facility_id' => $facilityId];

    $this->actingAs($user)
        ->withSession($session)
        ->get(route('laboratory.catalog.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Laboratory/Catalog')
            ->has('definitions')
            ->has('panels')
            ->has('methods')
            ->where('canManageCatalog', false));

    $this->actingAs($user)
        ->withSession($session)
        ->post(route('laboratory.catalog.tests.store'), laboratoryCatalogTestPayload())
        ->assertForbidden();

    $this->actingAs($user)
        ->withSession($session)
        ->post(route('laboratory.catalog.panels.store'), [])
        ->assertForbidden();

    $this->actingAs($user)
        ->withSession($session)
        ->post(route('laboratory.catalog.tests.methods.store', (string) Str::uuid7()), [])
        ->assertForbidden();

    $this->actingAs($user)
        ->withSession($session)
        ->get(route('laboratory.catalog-editors.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Identity/LaboratoryCatalogEditors')
            ->has('candidates', 1)
            ->where('auth.isTenantAdmin', true));

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.catalog-editors.index'))
        ->post(route('laboratory.catalog-editors.grant', $userId))
        ->assertRedirect(route('laboratory.catalog-editors.index'));

    $assignment = TenantContextScope::run($tenantId, null, fn () => DB::table('idn_access_assignments as aa')
        ->join('idn_roles as r', 'r.id', '=', 'aa.role_id')
        ->where('aa.user_id', $userId)
        ->where('r.key', 'laboratory_catalog_editor')
        ->where('aa.status', 'ACTIVE')
        ->whereNull('aa.organization_id')
        ->whereNull('aa.facility_id')
        ->first());
    expect($assignment)->not->toBeNull();

    $this->actingAs($user)
        ->withSession($session)
        ->get(route('laboratory.catalog.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Laboratory/Catalog')
            ->has('definitions')
            ->has('panels')
            ->has('methods')
            ->has('inventoryItems')
            ->where('canManageCatalog', true));

    $technicianAssignmentId = TenantContextScope::run($tenantId, null, fn () => DB::table('idn_access_assignments as aa')
        ->join('idn_roles as r', 'r.id', '=', 'aa.role_id')
        ->where('aa.user_id', $userId)
        ->where('r.key', 'laboratory_technician')
        ->value('aa.id'));
    TenantContextScope::run($tenantId, null, fn () => DB::table('idn_access_assignments')
        ->where('id', $technicianAssignmentId)
        ->update(['status' => 'REVOKED']));

    $this->actingAs($user)
        ->withSession($session)
        ->get(route('laboratory.catalog.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManageCatalog', false));

    $this->actingAs($user)
        ->withSession($session)
        ->post(route('laboratory.catalog.tests.methods.store', (string) Str::uuid7()), [])
        ->assertForbidden();

    TenantContextScope::run($tenantId, null, fn () => DB::table('idn_access_assignments')
        ->where('id', $technicianAssignmentId)
        ->update(['status' => 'ACTIVE']));
    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.catalog-editors.index'))
        ->delete(route('laboratory.catalog-editors.revoke', $userId))
        ->assertRedirect(route('laboratory.catalog-editors.index'));

    $this->actingAs($user)
        ->withSession($session)
        ->get(route('laboratory.catalog.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManageCatalog', false));
});

it('does not grant Facility Admin access to the laboratory catalog', function (): void {
    ['facility' => $facility, 'user' => $user] = laboratoryFixture(FacilityAdminRoleDefinition::PERMISSIONS);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get(route('laboratory.catalog.index'))
        ->assertForbidden();
});

it('creates and versions tests with structured result parameters and audit history', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $session = ['active_facility_id' => $facilityId];

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.catalog.index'))
        ->post(route('laboratory.catalog.tests.store'), laboratoryCatalogTestPayload())
        ->assertRedirect(route('laboratory.catalog.index'));

    $definition = TenantContextScope::run($tenantId, null, fn () => LabTestDefinition::query()->where('code', 'FBC')->firstOrFail());
    expect($definition->version)->toBe(1)
        ->and($definition->specimen_type)->toBe('WHOLE_BLOOD')
        ->and($definition->container_type)->toBe('EDTA_TUBE')
        ->and($definition->parameters[0]['value_type'])->toBe('NUMERIC')
        ->and($definition->parameters[0]['reference_ranges']['low'])->toEqual(12)
        ->and($definition->parameters[1]['coded_values'])->toHaveCount(2);

    $updatePayload = laboratoryCatalogTestPayload([
        'name' => 'Full Blood Count — updated',
        'availability_status' => 'REFERRAL_ONLY',
        'turnaround_target_minutes' => null,
    ]);
    unset($updatePayload['code']);

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.catalog.index'))
        ->put(route('laboratory.catalog.tests.update', $definition->id), $updatePayload)
        ->assertRedirect(route('laboratory.catalog.index'));

    $updated = TenantContextScope::run($tenantId, null, fn () => LabTestDefinition::query()->findOrFail($definition->id));
    expect($updated->version)->toBe(2)
        ->and($updated->name)->toBe('Full Blood Count — updated')
        ->and($updated->availability_status)->toBe('REFERRAL_ONLY')
        ->and($updated->turnaround_target_minutes)->toBeNull()
        ->and($updated->isOrderable())->toBeFalse();

    expect(TenantContextScope::run($tenantId, null, fn () => DB::table('shared_audit_log')
        ->where('target_id', $definition->id)
        ->whereIn('action', ['laboratory.test_defined', 'laboratory.test_updated'])
        ->count()))->toBe(2);
});

it('rejects reversed reference limits and coded parameters without options', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $session = ['active_facility_id' => (string) $facility->id];
    $payload = laboratoryCatalogTestPayload([
        'parameters' => [
            [
                'code' => 'HB',
                'name' => 'Haemoglobin',
                'value_type' => 'NUMERIC',
                'unit' => 'g/dL',
                'reference_ranges' => ['low' => 16, 'high' => 12],
                'coded_values' => [],
            ],
            [
                'code' => 'RESULT',
                'name' => 'Result',
                'value_type' => 'CODED',
                'coded_values' => [],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.catalog.index'))
        ->post(route('laboratory.catalog.tests.store'), $payload)
        ->assertRedirect(route('laboratory.catalog.index'))
        ->assertSessionHasErrors([
            'parameters.0.reference_ranges',
            'parameters.1.coded_values',
        ]);

    expect(TenantContextScope::run((string) $tenant->id, null, fn () => DB::table('lab_test_definitions')->where('code', 'FBC')->exists()))->toBeFalse();
});

it('creates a panel with only active available tests and rejects cross-tenant members', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $definition = seedLabTestDefinition($tenantId, ['code' => 'GLU', 'name' => 'Glucose']);
    $otherTenant = Tenant::factory()->create();
    $foreignDefinition = seedLabTestDefinition((string) $otherTenant->id, ['code' => 'K', 'name' => 'Potassium']);
    $session = ['active_facility_id' => $facilityId];

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.catalog.index'))
        ->post(route('laboratory.catalog.panels.store'), [
            'code' => 'CHEM',
            'name' => 'Chemistry Panel',
            'category' => 'BIOCHEMISTRY',
            'description' => '',
            'member_test_ids' => [(string) $definition->id],
        ])
        ->assertRedirect(route('laboratory.catalog.index'));

    $panel = TenantContextScope::run($tenantId, null, fn () => DB::table('lab_panels')->where('code', 'CHEM')->first());
    expect($panel)->not->toBeNull();
    expect(TenantContextScope::run($tenantId, null, fn () => DB::table('lab_panel_members')
        ->where('panel_id', $panel->id)
        ->where('test_definition_id', $definition->id)
        ->exists()))->toBeTrue();

    $secondDefinition = seedLabTestDefinition($tenantId, ['code' => 'UREA', 'name' => 'Urea']);
    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.catalog.index'))
        ->put(route('laboratory.catalog.panels.update', $panel->id), [
            'name' => 'Expanded Chemistry Panel',
            'category' => 'BIOCHEMISTRY',
            'description' => '',
            'member_test_ids' => [(string) $definition->id, (string) $secondDefinition->id],
            'active' => false,
        ])
        ->assertRedirect(route('laboratory.catalog.index'));

    $updatedPanel = TenantContextScope::run($tenantId, null, fn () => DB::table('lab_panels')->where('id', $panel->id)->first());
    expect($updatedPanel->name)->toBe('Expanded Chemistry Panel')
        ->and((bool) $updatedPanel->active)->toBeFalse()
        ->and((int) $updatedPanel->version)->toBe(2);
    expect(TenantContextScope::run($tenantId, null, fn () => DB::table('lab_panel_members')->where('panel_id', $panel->id)->count()))->toBe(2);

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.catalog.index'))
        ->post(route('laboratory.catalog.panels.store'), [
            'code' => 'FOREIGN',
            'name' => 'Foreign Test Panel',
            'member_test_ids' => [(string) $foreignDefinition->id],
        ])
        ->assertRedirect(route('laboratory.catalog.index'))
        ->assertSessionHasErrors('member_test_ids.0');

    expect(TenantContextScope::run($tenantId, null, fn () => DB::table('lab_panels')->where('code', 'FOREIGN')->exists()))->toBeFalse();
});

it('creates and versions test methods with Inventory-backed material snapshots', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $definition = seedLabTestDefinition($tenantId, ['code' => 'FBC-METHOD']);
    $reagent = TenantContextScope::run($tenantId, null, fn () => Item::factory()
        ->forTenant($tenantId)
        ->create([
            'code' => 'CBC-REAGENT',
            'name' => 'CBC reagent',
            'item_type' => 'CONSUMABLE',
            'category' => 'DIAGNOSTIC_REAGENT',
            'uom' => 'ML',
        ]));
    $control = TenantContextScope::run($tenantId, null, fn () => Item::factory()
        ->forTenant($tenantId)
        ->create([
            'code' => 'CBC-CONTROL',
            'name' => 'CBC control',
            'item_type' => 'SUPPLY',
            'category' => 'OTHER',
            'uom' => 'VIAL',
        ]));
    $session = ['active_facility_id' => $facilityId];
    $payload = [
        'code' => 'CBC-AUTO',
        'name' => 'Automated CBC',
        'description' => 'Automated hematology analyzer.',
        'specimen_type' => 'WHOLE_BLOOD',
        'container_type' => 'EDTA_TUBE',
        'requirements' => [
            [
                'inventory_item_id' => (string) $reagent->id,
                'role' => 'REAGENT',
                'required' => true,
                'usage_scope' => 'PER_TEST',
                'quantity' => '0.5000',
            ],
            [
                'inventory_item_id' => (string) $control->id,
                'role' => 'CONTROL',
                'required' => false,
                'usage_scope' => 'PER_RUN',
                'quantity' => '2',
            ],
        ],
    ];

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.catalog.index'))
        ->post(route('laboratory.catalog.tests.methods.store', (string) $definition->id), $payload)
        ->assertRedirect(route('laboratory.catalog.index'));

    $method = TenantContextScope::run($tenantId, null, fn () => DB::table('lab_test_methods')
        ->where('test_definition_id', $definition->id)
        ->where('code', 'CBC-AUTO')
        ->first());
    expect($method)->not->toBeNull()
        ->and((int) $method->version)->toBe(1);

    $requirements = TenantContextScope::run($tenantId, null, fn () => DB::table('lab_method_inventory_requirements')
        ->where('method_id', $method->id)
        ->where('method_version', 1)
        ->orderBy('requirement_role')
        ->get());
    expect($requirements)->toHaveCount(2)
        ->and($requirements[1]->item_code_snapshot)->toBe('CBC-REAGENT')
        ->and($requirements[1]->item_uom_snapshot)->toBe('ML')
        ->and($requirements[1]->item_type_snapshot)->toBe('CONSUMABLE')
        ->and($requirements[1]->item_category_snapshot)->toBe('DIAGNOSTIC_REAGENT');

    $this->actingAs($user)
        ->withSession($session)
        ->get(route('laboratory.catalog.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('methods.0.code', 'CBC-AUTO')
            ->has('methods.0.requirements', 2));

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.catalog.index'))
        ->put(route('laboratory.catalog.tests.methods.update', [(string) $definition->id, $method->id]), [
            'name' => 'Automated CBC v2',
            'description' => 'Updated analyzer method.',
            'specimen_type' => 'WHOLE_BLOOD',
            'container_type' => 'EDTA_TUBE',
            'active' => false,
            'requirements' => [],
        ])
        ->assertRedirect(route('laboratory.catalog.index'))
        ->assertSessionHasNoErrors();

    $updatedMethod = TenantContextScope::run($tenantId, null, fn () => DB::table('lab_test_methods')->where('id', $method->id)->first());
    expect((int) $updatedMethod->version)->toBe(2)
        ->and((bool) $updatedMethod->active)->toBeFalse()
        ->and(TenantContextScope::run($tenantId, null, fn () => DB::table('lab_method_inventory_requirements')->where('method_id', $method->id)->count()))->toBe(2)
        ->and(TenantContextScope::run($tenantId, null, fn () => DB::table('shared_audit_log')
            ->where('target_id', $method->id)
            ->whereIn('action', ['laboratory.test_method_defined', 'laboratory.test_method_updated'])
            ->count()))->toBe(2);

    $this->actingAs($user)
        ->withSession($session)
        ->get(route('laboratory.catalog.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('methods.0.version', 2)
            ->has('methods.0.requirements', 0));
});

it('rejects Inventory items that are not active supplies or consumables', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $definition = seedLabTestDefinition($tenantId, ['code' => 'METHOD-INVALID']);
    $equipment = TenantContextScope::run($tenantId, null, fn () => Item::factory()
        ->forTenant($tenantId)
        ->create([
            'code' => 'ANALYZER',
            'item_type' => 'EQUIPMENT',
            'category' => 'EQUIPMENT',
        ]));
    $session = ['active_facility_id' => (string) $facility->id];

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.catalog.index'))
        ->post(route('laboratory.catalog.tests.methods.store', (string) $definition->id), [
            'code' => 'INVALID-EQUIPMENT',
            'name' => 'Invalid equipment material',
            'requirements' => [[
                'inventory_item_id' => (string) $equipment->id,
                'role' => 'CONSUMABLE',
                'required' => true,
                'usage_scope' => 'PER_TEST',
                'quantity' => '1',
            ]],
        ])
        ->assertRedirect(route('laboratory.catalog.index'))
        ->assertSessionHasErrors('requirements.0.inventory_item_id');

    expect(TenantContextScope::run($tenantId, null, fn () => DB::table('lab_test_methods')
        ->where('test_definition_id', $definition->id)
        ->exists()))->toBeFalse();
});

it('rejects inactive Inventory items', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $definition = seedLabTestDefinition($tenantId, ['code' => 'METHOD-INACTIVE-ITEM']);
    $item = TenantContextScope::run($tenantId, null, fn () => Item::factory()
        ->forTenant($tenantId)
        ->inactive()
        ->create([
            'item_type' => 'CONSUMABLE',
            'category' => 'DIAGNOSTIC_REAGENT',
        ]));

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->from(route('laboratory.catalog.index'))
        ->post(route('laboratory.catalog.tests.methods.store', (string) $definition->id), [
            'code' => 'INACTIVE-ITEM',
            'name' => 'Inactive Inventory item',
            'requirements' => [[
                'inventory_item_id' => (string) $item->id,
                'role' => 'REAGENT',
                'required' => true,
                'usage_scope' => 'PER_TEST',
                'quantity' => '1',
            ]],
        ])
        ->assertRedirect(route('laboratory.catalog.index'))
        ->assertSessionHasErrors('requirements.0.inventory_item_id');

    expect(TenantContextScope::run($tenantId, null, fn () => DB::table('lab_test_methods')
        ->where('test_definition_id', $definition->id)
        ->exists()))->toBeFalse();
});

it('does not resolve a foreign tenant inventory item while saving method requirements', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $definition = seedLabTestDefinition($tenantId, ['code' => 'METHOD-FOREIGN']);
    $otherTenant = Tenant::factory()->create();
    $foreignItem = TenantContextScope::run((string) $otherTenant->id, null, fn () => Item::factory()
        ->forTenant((string) $otherTenant->id)
        ->create([
            'item_type' => 'CONSUMABLE',
            'category' => 'DIAGNOSTIC_REAGENT',
        ]));

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->from(route('laboratory.catalog.index'))
        ->post(route('laboratory.catalog.tests.methods.store', (string) $definition->id), [
            'code' => 'FOREIGN-ITEM',
            'name' => 'Foreign item method',
            'requirements' => [[
                'inventory_item_id' => (string) $foreignItem->id,
                'role' => 'REAGENT',
                'required' => true,
                'usage_scope' => 'PER_TEST',
                'quantity' => '1',
            ]],
        ])
        ->assertRedirect(route('laboratory.catalog.index'))
        ->assertSessionHasErrors('requirements.0.inventory_item_id');

    expect(TenantContextScope::run($tenantId, null, fn () => DB::table('lab_test_methods')
        ->where('test_definition_id', $definition->id)
        ->exists()))->toBeFalse();
});

it('requires reagent roles to reference items categorized as diagnostic reagents', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $definition = seedLabTestDefinition($tenantId, ['code' => 'REAGENT-CATEGORY']);
    $supply = TenantContextScope::run($tenantId, null, fn () => Item::factory()
        ->forTenant($tenantId)
        ->create([
            'item_type' => 'SUPPLY',
            'category' => 'OTHER',
        ]));

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->from(route('laboratory.catalog.index'))
        ->post(route('laboratory.catalog.tests.methods.store', (string) $definition->id), [
            'code' => 'WRONG-REAGENT-CATEGORY',
            'name' => 'Wrong reagent category',
            'requirements' => [[
                'inventory_item_id' => (string) $supply->id,
                'role' => 'REAGENT',
                'required' => true,
                'usage_scope' => 'PER_TEST',
                'quantity' => '1',
            ]],
        ])
        ->assertRedirect(route('laboratory.catalog.index'))
        ->assertSessionHasErrors('requirements.0.inventory_item_id');

    expect(TenantContextScope::run($tenantId, null, fn () => DB::table('lab_test_methods')
        ->where('test_definition_id', $definition->id)
        ->exists()))->toBeFalse();
});

it('rejects nonpositive and over-precision material quantities', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $definition = seedLabTestDefinition($tenantId, ['code' => 'INVALID-QUANTITY']);
    $item = TenantContextScope::run($tenantId, null, fn () => Item::factory()
        ->forTenant($tenantId)
        ->create([
            'item_type' => 'CONSUMABLE',
            'category' => 'DIAGNOSTIC_REAGENT',
        ]));
    $secondItem = TenantContextScope::run($tenantId, null, fn () => Item::factory()
        ->forTenant($tenantId)
        ->create([
            'item_type' => 'CONSUMABLE',
            'category' => 'DIAGNOSTIC_REAGENT',
        ]));

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->from(route('laboratory.catalog.index'))
        ->post(route('laboratory.catalog.tests.methods.store', (string) $definition->id), [
            'code' => 'INVALID-QUANTITY',
            'name' => 'Invalid quantity method',
            'requirements' => [
                [
                    'inventory_item_id' => (string) $item->id,
                    'role' => 'REAGENT',
                    'required' => true,
                    'usage_scope' => 'PER_TEST',
                    'quantity' => '0',
                ],
                [
                    'inventory_item_id' => (string) $secondItem->id,
                    'role' => 'REAGENT',
                    'required' => true,
                    'usage_scope' => 'PER_TEST',
                    'quantity' => '0.00001',
                ],
            ],
        ])
        ->assertRedirect(route('laboratory.catalog.index'))
        ->assertSessionHasErrors(['requirements.0.quantity', 'requirements.1.quantity']);

    expect(TenantContextScope::run($tenantId, null, fn () => DB::table('lab_test_methods')
        ->where('test_definition_id', $definition->id)
        ->exists()))->toBeFalse();
});

it('does not let facility assignment managers grant tenant-wide catalog editor access', function (): void {
    ['facility' => $facility, 'user' => $user] = laboratoryFixture(['identity.assignments.manage']);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get(route('laboratory.catalog-editors.index'))
        ->assertForbidden();
});

it('registers facility instruments with active method compatibility and Inventory equipment snapshots', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $definition = seedLabTestDefinition($tenantId, ['code' => 'INSTRUMENT-TEST']);
    $method = TenantContextScope::run($tenantId, null, fn () => LabTestMethod::query()->create([
        'tenant_id' => $tenantId,
        'test_definition_id' => $definition->id,
        'code' => 'INSTRUMENT-METHOD',
        'name' => 'Instrument method',
        'active' => true,
        'version' => 1,
    ]));
    $equipment = TenantContextScope::run($tenantId, null, fn () => Item::factory()
        ->forTenant($tenantId)
        ->create([
            'code' => 'CBC-ANALYZER',
            'name' => 'CBC analyzer model',
            'item_type' => 'EQUIPMENT',
            'category' => 'LABORATORY_EQUIPMENT',
        ]));
    $session = ['active_facility_id' => $facilityId];

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.instruments.index'))
        ->post(route('laboratory.instruments.store'), [
            'identifier' => 'CBC-01',
            'name' => 'Haematology analyzer',
            'manufacturer' => 'Example Manufacturer',
            'model' => 'CBC 3000',
            'serial_number' => 'SN-0001',
            'inventory_item_id' => (string) $equipment->id,
            'supported_method_ids' => [(string) $method->id],
        ])
        ->assertRedirect(route('laboratory.instruments.index'));

    $instrument = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('lab_facility_instruments')
        ->where('identifier', 'CBC-01')
        ->first());
    $compatibility = TenantContextScope::run($tenantId, null, fn () => DB::table('lab_facility_instrument_methods')
        ->where('instrument_id', $instrument->id)
        ->where('instrument_version', $instrument->version)
        ->first());

    expect($instrument)->not->toBeNull()
        ->and($instrument->facility_id)->toBe($facilityId)
        ->and($instrument->inventory_item_id)->toBe((string) $equipment->id)
        ->and($instrument->inventory_name_snapshot)->toBe('CBC analyzer model');
    expect($compatibility)->not->toBeNull()
        ->and($compatibility->method_id)->toBe((string) $method->id)
        ->and($compatibility->method_version)->toBe(1);

    $this->actingAs($user)
        ->withSession($session)
        ->get(route('laboratory.instruments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Laboratory/Instruments')
            ->has('instruments', 1)
            ->where('instruments.0.identifier', 'CBC-01')
            ->where('instruments.0.supportedMethods.0.methodId', (string) $method->id)
            ->has('equipmentItems', 1)
            ->has('methods', 1));
});

it('versions instrument compatibility and prevents reactivating a decommissioned instrument', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $definition = seedLabTestDefinition($tenantId, ['code' => 'CHEM-INSTRUMENT']);
    $method = TenantContextScope::run($tenantId, null, fn () => LabTestMethod::query()->create([
        'tenant_id' => $tenantId,
        'test_definition_id' => $definition->id,
        'code' => 'CHEM-METHOD',
        'name' => 'Chemistry method',
        'active' => true,
        'version' => 1,
    ]));
    $session = ['active_facility_id' => $facilityId];

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.instruments.index'))
        ->post(route('laboratory.instruments.store'), [
            'identifier' => 'CHEM-01',
            'name' => 'Chemistry analyzer',
            'supported_method_ids' => [(string) $method->id],
        ])
        ->assertRedirect(route('laboratory.instruments.index'));
    $instrument = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('lab_facility_instruments')
        ->where('identifier', 'CHEM-01')
        ->first());

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.instruments.index'))
        ->put(route('laboratory.instruments.update', $instrument->id), [
            'identifier' => 'CHEM-01',
            'name' => 'Chemistry analyzer',
            'status' => 'OUT_OF_SERVICE',
            'supported_method_ids' => [],
        ])
        ->assertRedirect(route('laboratory.instruments.index'));

    $outOfService = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('lab_facility_instruments')
        ->where('id', $instrument->id)
        ->first());
    expect($outOfService->status)->toBe('OUT_OF_SERVICE')
        ->and((int) $outOfService->version)->toBe(1);
    expect(TenantContextScope::run($tenantId, null, fn () => DB::table('lab_facility_instrument_methods')
        ->where('instrument_id', $instrument->id)
        ->where('instrument_version', 0)
        ->where('method_id', $method->id)
        ->exists()))->toBeTrue();
    expect(TenantContextScope::run($tenantId, null, fn () => DB::table('lab_facility_instrument_methods')
        ->where('instrument_id', $instrument->id)
        ->where('instrument_version', 1)
        ->exists()))->toBeFalse();

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.instruments.index'))
        ->put(route('laboratory.instruments.update', $instrument->id), [
            'identifier' => 'CHEM-01',
            'name' => 'Chemistry analyzer',
            'status' => 'DECOMMISSIONED',
            'supported_method_ids' => [],
        ])
        ->assertRedirect(route('laboratory.instruments.index'));

    $decommissioned = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('lab_facility_instruments')
        ->where('id', $instrument->id)
        ->first());
    expect($decommissioned->status)->toBe('DECOMMISSIONED')
        ->and((int) $decommissioned->version)->toBe(2);

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('laboratory.instruments.index'))
        ->put(route('laboratory.instruments.update', $instrument->id), [
            'identifier' => 'CHEM-01',
            'name' => 'Chemistry analyzer',
            'status' => 'ACTIVE',
            'supported_method_ids' => [],
        ])
        ->assertInvalid([
            'status' => 'A decommissioned instrument cannot be returned to service.',
        ]);

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('lab_facility_instruments')
        ->where('id', $instrument->id)
        ->value('status')))->toBe('DECOMMISSIONED');
});

it('rejects non-equipment Inventory items when registering an instrument', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $item = TenantContextScope::run($tenantId, null, fn () => Item::factory()
        ->forTenant($tenantId)
        ->create([
            'item_type' => 'SUPPLY',
            'category' => 'OTHER',
        ]));

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->from(route('laboratory.instruments.index'))
        ->post(route('laboratory.instruments.store'), [
            'identifier' => 'INVALID-EQUIPMENT-LINK',
            'name' => 'Analyzer with invalid inventory link',
            'inventory_item_id' => (string) $item->id,
            'supported_method_ids' => [],
        ])
        ->assertInvalid([
            'inventory_item_id' => 'Select an active Inventory equipment item',
        ]);

    expect(TenantContextScope::run($tenantId, (string) $facility->id, fn () => DB::table('lab_facility_instruments')
        ->where('identifier', 'INVALID-EQUIPMENT-LINK')
        ->exists()))->toBeFalse();
});

it('rejects inactive methods when configuring instrument compatibility', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $definition = seedLabTestDefinition($tenantId, ['code' => 'INACTIVE-INSTRUMENT-METHOD']);
    $inactiveMethod = TenantContextScope::run($tenantId, null, fn () => LabTestMethod::query()->create([
        'tenant_id' => $tenantId,
        'test_definition_id' => $definition->id,
        'code' => 'INACTIVE-METHOD',
        'name' => 'Inactive method',
        'active' => false,
        'version' => 1,
    ]));

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->from(route('laboratory.instruments.index'))
        ->post(route('laboratory.instruments.store'), [
            'identifier' => 'INVALID-METHOD-LINK',
            'name' => 'Analyzer with invalid method',
            'supported_method_ids' => [(string) $inactiveMethod->id],
        ])
        ->assertInvalid([
            'supported_method_ids' => 'Choose only active methods for available laboratory tests',
        ]);

    expect(TenantContextScope::run($tenantId, (string) $facility->id, fn () => DB::table('lab_facility_instruments')
        ->where('identifier', 'INVALID-METHOD-LINK')
        ->exists()))->toBeFalse();
});

it('rejects an instrument identifier already registered at the same facility', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $payload = [
        'identifier' => 'DUPLICATE-01',
        'name' => 'Analyzer',
        'supported_method_ids' => [],
    ];

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->from(route('laboratory.instruments.index'))
        ->post(route('laboratory.instruments.store'), $payload)
        ->assertRedirect(route('laboratory.instruments.index'));

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->from(route('laboratory.instruments.index'))
        ->post(route('laboratory.instruments.store'), $payload)
        ->assertInvalid([
            'identifier' => 'already been taken',
        ]);

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('lab_facility_instruments')
        ->where('identifier', 'DUPLICATE-01')
        ->count()))->toBe(1);
});

it('does not reveal or update an instrument owned by another tenant', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    ['tenant' => $otherTenant, 'facility' => $otherFacility] = laboratoryFixture();
    $foreignInstrumentId = TenantContextScope::run(
        (string) $otherTenant->id,
        (string) $otherFacility->id,
        function () use ($otherTenant, $otherFacility): string {
            $instrument = LabFacilityInstrument::register([
                'tenantId' => (string) $otherTenant->id,
                'facilityId' => (string) $otherFacility->id,
                'identifier' => 'FOREIGN-01',
                'name' => 'Foreign analyzer',
                'manufacturer' => null,
                'model' => null,
                'serialNumber' => null,
                'inventoryItemId' => null,
                'inventoryCodeSnapshot' => null,
                'inventoryNameSnapshot' => null,
            ]);
            $instrument->save();

            return (string) $instrument->id;
        },
    );

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->put(route('laboratory.instruments.update', $foreignInstrumentId), [
            'identifier' => 'FOREIGN-01',
            'name' => 'Unauthorized update',
            'status' => 'ACTIVE',
            'supported_method_ids' => [],
        ])
        ->assertNotFound();
});

it('denies instrument setup outside the active facility or to a user without catalog governance', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryCatalogAdminFixture(grantEditor: true);
    $otherFacility = Facility::factory()
        ->forTenant((string) $tenant->id, (string) $facility->organization_id)
        ->create();

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $otherFacility->id])
        ->get(route('laboratory.instruments.index'))
        ->assertForbidden();

    ['facility' => $nonEditorFacility, 'user' => $nonEditor] = laboratoryCatalogAdminFixture();
    $this->actingAs($nonEditor)
        ->withSession(['active_facility_id' => (string) $nonEditorFacility->id])
        ->get(route('laboratory.instruments.index'))
        ->assertForbidden();
});
