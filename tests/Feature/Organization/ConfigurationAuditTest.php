<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('facility create and update commands audit the changed facility atomically', function (): void {
    $tenant = Tenant::factory()->create();
    $organization = TenantContextScope::run($tenant->id, null, fn (): Organization => Organization::factory()
        ->forTenant($tenant->id)
        ->create());

    expect(Artisan::call('afyanova:org:facility', [
        'action' => 'create',
        '--tenant' => $tenant->id,
        '--organization' => $organization->id,
        '--code' => 'CENTRAL',
        '--name' => 'Central Clinic',
    ]))->toBe(0);

    $facility = TenantContextScope::run($tenant->id, null, fn (): Facility => Facility::query()
        ->where('code', 'CENTRAL')
        ->firstOrFail());

    expect(Artisan::call('afyanova:org:facility', [
        'action' => 'update',
        '--tenant' => $tenant->id,
        '--code' => 'CENTRAL',
        '--status' => 'SUSPENDED',
    ]))->toBe(0);

    $events = TenantContextScope::run($tenant->id, null, fn () => DB::table('shared_audit_log')
        ->where('target_id', $facility->id)
        ->orderBy('created_at')
        ->get());

    expect($events)->toHaveCount(2)
        ->and($events->pluck('action')->all())->toBe([
            'organization.facility.created',
            'organization.facility.updated',
        ])
        ->and($events->pluck('facility_id')->unique()->all())->toBe([$facility->id]);
});

test('facility structure command audits department, unit, and room creation against the target facility', function (): void {
    $tenant = Tenant::factory()->create();
    $facility = TenantContextScope::run($tenant->id, null, function () use ($tenant): Facility {
        $organization = Organization::factory()->forTenant($tenant->id)->create();

        return Facility::factory()->forTenant($tenant->id, $organization->id)->create();
    });

    expect(Artisan::call('afyanova:org:structure', [
        'kind' => 'department',
        '--tenant' => $tenant->id,
        '--facility' => $facility->id,
        '--code' => 'OPD',
        '--name' => 'Outpatient',
    ]))->toBe(0);

    $departmentId = TenantContextScope::run($tenant->id, $facility->id, fn (): string => (string) DB::table('org_departments')
        ->where('facility_id', $facility->id)
        ->where('code', 'OPD')
        ->value('id'));

    expect(Artisan::call('afyanova:org:structure', [
        'kind' => 'unit',
        '--tenant' => $tenant->id,
        '--facility' => $facility->id,
        '--department' => $departmentId,
        '--code' => 'TRIAGE',
        '--name' => 'Triage',
    ]))->toBe(0);

    $unitId = TenantContextScope::run($tenant->id, $facility->id, fn (): string => (string) DB::table('org_units')
        ->where('facility_id', $facility->id)
        ->where('code', 'TRIAGE')
        ->value('id'));

    expect(Artisan::call('afyanova:org:structure', [
        'kind' => 'room',
        '--tenant' => $tenant->id,
        '--facility' => $facility->id,
        '--unit' => $unitId,
        '--code' => 'TRIAGE-1',
        '--name' => 'Triage Room',
    ]))->toBe(0);

    $events = TenantContextScope::run($tenant->id, $facility->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'like', 'organization.structure.%')
        ->orderBy('action')
        ->get());

    expect($events)->toHaveCount(3)
        ->and($events->pluck('action')->all())->toBe([
            'organization.structure.department_created',
            'organization.structure.room_created',
            'organization.structure.unit_created',
        ])
        ->and($events->pluck('facility_id')->unique()->all())->toBe([$facility->id]);
});
