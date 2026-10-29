<?php

declare(strict_types=1);

use App\Domains\HR\Domain\Model\Cadre;
use App\Domains\HR\Domain\Model\Position;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Organization\Domain\Model\Organization;
use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Database\Seeders\FacilityDskDispensaryJobStructureSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('seeds an idempotent job structure for the tenant owning DSK Dispensary only', function (): void {
    $targetTenant = Tenant::factory()->create(['name' => 'AfyaNova Demo Health']);
    $otherTenant = Tenant::factory()->create(['name' => 'Other Dispensary']);

    $createOrganization = static fn (Tenant $tenant): Organization => TenantContextScope::run(
        (string) $tenant->id,
        null,
        fn (): Organization => Organization::factory()->forTenant((string) $tenant->id)->create(),
    );
    $targetOrganization = $createOrganization($targetTenant);
    $otherOrganization = $createOrganization($otherTenant);

    $createFacility = static function (Tenant $tenant, Organization $organization, string $name, string $code): Facility {
        return TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $organization, $name, $code): Facility {
            return Facility::factory()
                ->forTenant((string) $tenant->id, (string) $organization->id)
                ->ofType('DISPENSARY', 'PRIMARY')
                ->create(['name' => $name, 'code' => $code]);
        });
    };

    $createFacility($targetTenant, $targetOrganization, 'DSK Dispensary', 'DSK_DISP');
    $createFacility($targetTenant, $targetOrganization, 'Demo Dispensary', 'DEMO_DISP');
    $createFacility($otherTenant, $otherOrganization, 'Other Dispensary', 'OTHER_DISP');

    $seeder = app(FacilityDskDispensaryJobStructureSeeder::class);
    $seeder->run();
    $seeder->run();

    $targetCounts = TenantContextScope::run((string) $targetTenant->id, null, fn (): array => [
        'cadres' => Cadre::query()->where('is_active', true)->count(),
        'positions' => Position::query()->where('is_active', true)->count(),
        'linkedPositions' => Position::query()
            ->where('is_active', true)
            ->whereHas('cadre', fn ($query) => $query->where('tenant_id', $targetTenant->id))
            ->count(),
        'pharmacyCategory' => Cadre::query()
            ->where('code', 'PHARMACEUTICAL_SERVICES')
            ->firstOrFail()
            ->category
            ->value,
        'clinicalOfficerCadre' => Position::query()
            ->where('code', 'CLINICAL_OFFICER')
            ->firstOrFail()
            ->cadre
            ->code,
        'nurseCadre' => Position::query()
            ->where('code', 'NURSE')
            ->firstOrFail()
            ->cadre
            ->code,
        'serviceAreaPositions' => Position::query()
            ->whereIn('code', ['CO_OUTPATIENT', 'NURSE_RCH'])
            ->where('is_active', true)
            ->count(),
    ]);
    $otherCounts = TenantContextScope::run((string) $otherTenant->id, null, fn (): array => [
        'cadres' => Cadre::query()->where('is_active', true)->count(),
        'positions' => Position::query()->where('is_active', true)->count(),
    ]);

    expect($targetCounts)->toBe([
        'cadres' => 6,
        'positions' => 9,
        'linkedPositions' => 9,
        'pharmacyCategory' => 'PHARMACY',
        'clinicalOfficerCadre' => 'CLINICAL_SERVICES',
        'nurseCadre' => 'NURSING_SERVICES',
        'serviceAreaPositions' => 0,
    ])
        ->and($otherCounts)->toBe(['cadres' => 0, 'positions' => 0]);
});

it('retires obsolete seeded positions without deleting historical records', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'AfyaNova Demo Health']);
    $organization = TenantContextScope::run(
        (string) $tenant->id,
        null,
        fn (): Organization => Organization::factory()->forTenant((string) $tenant->id)->create(),
    );
    $cadreId = (string) Str::uuid7();
    $positionId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $organization, $cadreId, $positionId): void {
        Facility::factory()
            ->forTenant((string) $tenant->id, (string) $organization->id)
            ->ofType('DISPENSARY', 'PRIMARY')
            ->create(['name' => 'DSK Dispensary', 'code' => 'DSK_DISP']);

        DB::table('hr_cadres')->insert([
            'id' => $cadreId,
            'tenant_id' => $tenant->id,
            'code' => 'CLINICAL_ASSISTANT',
            'name' => 'Clinical Assistant',
            'category' => 'CLINICAL',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('hr_positions')->insert([
            'id' => $positionId,
            'tenant_id' => $tenant->id,
            'code' => 'CA_OUTPATIENT',
            'title' => 'Clinical Assistant - Outpatient',
            'cadre_id' => $cadreId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    app(FacilityDskDispensaryJobStructureSeeder::class)->run();

    TenantContextScope::run((string) $tenant->id, null, function () use ($cadreId, $positionId): void {
        expect(Cadre::query()->findOrFail($cadreId)->is_active)->toBeFalse()
            ->and(Position::query()->findOrFail($positionId)->is_active)->toBeFalse();
    });
});

it('fails explicitly when the exact DSK dispensary target is missing', function (): void {
    Tenant::factory()->create(['name' => 'AfyaNova Demo Health']);

    expect(fn () => app(FacilityDskDispensaryJobStructureSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'Expected exactly one tenant owning the DSK dispensary facility');
});
