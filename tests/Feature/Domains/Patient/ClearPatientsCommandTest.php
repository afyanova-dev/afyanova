<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Domains\Patient\Domain\Model\Patient;
use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('it allows a full dry-run without requiring tenant or patient filters', function () {
    $this->artisan('patient:clear', ['--all' => true, '--dry-run' => true])->assertSuccessful();
});

test('it resolves an exact facility name and clears patients within that tenant context', function (): void {
    $tenant = Tenant::factory()->create();
    $tenantId = (string) $tenant->id;
    $facilityName = 'Demo General Hospital';

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityName): void {
        $organization = Organization::factory()->forTenant($tenantId)->create();
        Facility::factory()
            ->forTenant($tenantId, (string) $organization->id)
            ->create(['name' => $facilityName]);
        Patient::factory()->forTenant($tenantId)->create();
    });

    $this->artisan('patient:clear', ['--facility' => $facilityName, '--dry-run' => true])
        ->expectsOutput('Found 1 patient record(s).')
        ->assertSuccessful();

    $this->artisan('patient:clear', ['--facility' => $facilityName, '--force' => true])
        ->expectsOutputToContain('Deleted 1 patient record(s)')
        ->assertSuccessful();

    expect(TenantContextScope::run(
        $tenantId,
        null,
        fn (): int => DB::table('pat_patients')->count(),
    ))->toBe(0);
});

test('it refuses to resolve a facility name that matches multiple tenants', function (): void {
    $facilityName = 'Shared Facility Name';

    foreach (range(1, 2) as $_) {
        $tenant = Tenant::factory()->create();
        $tenantId = (string) $tenant->id;

        TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityName): void {
            $organization = Organization::factory()->forTenant($tenantId)->create();
            Facility::factory()
                ->forTenant($tenantId, (string) $organization->id)
                ->create(['name' => $facilityName]);
        });
    }

    $this->artisan('patient:clear', ['--facility' => $facilityName, '--dry-run' => true])
        ->expectsOutputToContain('More than one tenant has a facility named')
        ->assertFailed();
});
