<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Domains\Organization\Domain\Service\CapabilityDependencyValidator;
use App\Models\Tenant;
use App\Shared\Capability\FacilityCapabilities;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 3 gate — the facility capability model (ADR-004/005/006, ADR-013;
 | docs/00-foundation/06-facility-levels-and-capabilities.md §11, §16, §31,
 | §93, §107, §121). Capability, never facility type. Capability is not
 | authorization.
 */

beforeEach(function (): void {
    // global reference tables — no tenant_id, no RLS (IC §10).
    foreach ([
        ['OUTPATIENT_CARE', 'CLINICAL'], ['PHARMACY', 'CLINICAL'], ['LABORATORY', 'DIAGNOSTIC'],
        ['ANAESTHESIA', 'PERIOPERATIVE'], ['RECOVERY', 'PERIOPERATIVE'], ['THEATRE', 'PERIOPERATIVE'],
    ] as [$code, $category]) {
        DB::table('org_capabilities')->insert([
            'id' => (string) Str::uuid7(), 'code' => $code, 'name' => Str::headline($code),
            'category' => $category, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    foreach ([['THEATRE', 'ANAESTHESIA'], ['THEATRE', 'RECOVERY']] as [$cap, $dep]) {
        DB::table('org_capability_dependencies')->insert([
            'id' => (string) Str::uuid7(), 'capability_code' => $cap, 'depends_on_code' => $dep,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

/** @return array{tenant: Tenant, facility: string} */
function makeFacility(string $type = 'HOSPITAL', string $level = 'DISTRICT'): array
{
    $tenant = Tenant::factory()->create();
    $facilityId = TenantContextScope::run($tenant->id, null, function () use ($tenant, $type, $level): string {
        $org = Organization::factory()->forTenant($tenant->id)->create();

        return Facility::factory()->forTenant($tenant->id, $org->id)->ofType($type, $level)->create()->id;
    });

    return ['tenant' => $tenant, 'facility' => $facilityId];
}

function capability(Tenant $tenant, string $facilityId, string $action, string $code, ?string $reason = null): int
{
    return Artisan::call('afyanova:org:capability', array_filter([
        'action' => $action, '--tenant' => $tenant->id, '--facility' => $facilityId,
        '--capability' => $code, '--reason' => $reason,
    ], fn ($v) => $v !== null));
}

it('walks a capability through AVAILABLE → ENABLED → SUSPENDED → DISABLED', function (): void {
    ['tenant' => $tenant, 'facility' => $facilityId] = makeFacility();
    $caps = app(FacilityCapabilities::class);

    TenantContextScope::run($tenant->id, $facilityId, function () use ($caps, $tenant, $facilityId): void {
        expect($caps->state($facilityId, 'LABORATORY'))->toBeNull();

        expect(capability($tenant, $facilityId, 'enable', 'LABORATORY'))->toBe(0);
        $caps->flush($facilityId);
        expect($caps->has($facilityId, 'LABORATORY'))->toBeTrue()
            ->and($caps->canOperate($facilityId, 'LABORATORY'))->toBeTrue();

        expect(capability($tenant, $facilityId, 'suspend', 'LABORATORY', 'equipment recall'))->toBe(0);
        $caps->flush($facilityId);
        expect($caps->has($facilityId, 'LABORATORY'))->toBeFalse()
            ->and($caps->canOperate($facilityId, 'LABORATORY'))->toBeFalse();

        expect(capability($tenant, $facilityId, 'disable', 'LABORATORY'))->toBe(0);
        $caps->flush($facilityId);
        expect($caps->state($facilityId, 'LABORATORY'))->toBe('DISABLED')
            ->and($caps->canOperate($facilityId, 'LABORATORY'))->toBeFalse();
    });
});

it('refuses to enable THEATRE until its declared dependencies are enabled (ADR-013)', function (): void {
    ['tenant' => $tenant, 'facility' => $facilityId] = makeFacility();

    expect(capability($tenant, $facilityId, 'enable', 'THEATRE'))->toBe(1); // missing ANAESTHESIA, RECOVERY

    capability($tenant, $facilityId, 'enable', 'ANAESTHESIA');
    capability($tenant, $facilityId, 'enable', 'RECOVERY');

    expect(capability($tenant, $facilityId, 'enable', 'THEATRE'))->toBe(0);

    $caps = app(FacilityCapabilities::class);
    TenantContextScope::run($tenant->id, $facilityId, function () use ($caps, $facilityId): void {
        expect($caps->has($facilityId, 'THEATRE'))->toBeTrue();
    });
});

it('CapabilityDependencyValidator is a pure function over the enabled set + dependency map', function (): void {
    $validator = new CapabilityDependencyValidator;
    $map = ['THEATRE' => ['ANAESTHESIA', 'RECOVERY']];

    expect($validator->missingDependencies('THEATRE', [], $map))->toBe(['ANAESTHESIA', 'RECOVERY'])
        ->and($validator->missingDependencies('THEATRE', ['ANAESTHESIA'], $map))->toBe(['RECOVERY'])
        ->and($validator->missingDependencies('THEATRE', ['ANAESTHESIA', 'RECOVERY'], $map))->toBe([])
        ->and($validator->missingDependencies('PHARMACY', [], $map))->toBe([]);
});

it('keeps service availability independent of capability state (docs 06 §31)', function (): void {
    ['tenant' => $tenant, 'facility' => $facilityId] = makeFacility();
    capability($tenant, $facilityId, 'enable', 'LABORATORY');

    TenantContextScope::run($tenant->id, $facilityId, function () use ($tenant, $facilityId): void {
        DB::table('org_facility_services')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'facility_id' => $facilityId,
            'capability_code' => 'LABORATORY', 'service_code' => 'HISTOPATHOLOGY', 'name' => 'Histopathology',
            'available' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $caps = app(FacilityCapabilities::class);
        expect($caps->has($facilityId, 'LABORATORY'))->toBeTrue()
            ->and($caps->serviceAvailable($facilityId, 'HISTOPATHOLOGY'))->toBeFalse()
            ->and($caps->canOperate($facilityId, 'LABORATORY', 'HISTOPATHOLOGY'))->toBeFalse()
            ->and($caps->canOperate($facilityId, 'LABORATORY'))->toBeTrue();
    });
});

it('lets a dispensary and a hospital run entirely different capability sets', function (): void {
    ['tenant' => $tenantH, 'facility' => $hospital] = makeFacility('HOSPITAL', 'DISTRICT');
    ['tenant' => $tenantD, 'facility' => $dispensary] = makeFacility('DISPENSARY', 'PRIMARY');

    capability($tenantH, $hospital, 'enable', 'ANAESTHESIA');
    capability($tenantH, $hospital, 'enable', 'RECOVERY');
    capability($tenantH, $hospital, 'enable', 'THEATRE');
    capability($tenantD, $dispensary, 'enable', 'OUTPATIENT_CARE');
    capability($tenantD, $dispensary, 'enable', 'PHARMACY');

    $caps = app(FacilityCapabilities::class);

    TenantContextScope::run($tenantH->id, $hospital, function () use ($caps, $hospital): void {
        expect($caps->has($hospital, 'THEATRE'))->toBeTrue()
            ->and($caps->has($hospital, 'OUTPATIENT_CARE'))->toBeFalse();
    });
    TenantContextScope::run($tenantD->id, $dispensary, function () use ($caps, $dispensary): void {
        expect($caps->has($dispensary, 'PHARMACY'))->toBeTrue()
            ->and($caps->has($dispensary, 'THEATRE'))->toBeFalse();
    });
});

it('writes a shared_audit_log row for every capability change (docs 06 §93)', function (): void {
    ['tenant' => $tenant, 'facility' => $facilityId] = makeFacility();

    capability($tenant, $facilityId, 'enable', 'LABORATORY');
    capability($tenant, $facilityId, 'suspend', 'LABORATORY', 'audit finding');

    $rows = TenantContextScope::run($tenant->id, null, fn () => DB::table('shared_audit_log')
        ->whereIn('action', ['organization.capability.enable', 'organization.capability.suspend'])
        ->where('target_id', $facilityId)
        ->orderBy('created_at')
        ->get());

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->action)->toBe('organization.capability.enable')
        ->and($rows[0]->facility_id)->toBe($facilityId)
        ->and($rows[1]->reason)->toBe('audit finding');
});
