<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Patient\Presentation;

use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
});

it('keeps restriction sets identical across patients while data differs', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'clinical.allergies.read',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $withAllergy = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): Patient {
        $patient = Patient::factory()->forTenant($tenantId)->create([
            'given_name' => 'Amina', 'family_name' => 'Juma', 'display_name' => 'Amina Juma',
        ]);
        Allergy::factory()->forTenant($tenantId)->forPatient((string) $patient->id)->create([
            'facility_id' => $facilityId, 'substance_name' => 'Penicillin',
        ]);

        return $patient;
    });

    $withoutAllergy = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId): Patient {
        return Patient::factory()->forTenant($tenantId)->create([
            'given_name' => 'Baraka', 'family_name' => 'Moyo', 'display_name' => 'Baraka Moyo',
        ]);
    });

    $restrictedFor = function (string $patientId) use ($user, $facilityId): array {
        $response = test()->actingAs($user)
            ->withSession(['active_facility_id' => $facilityId])
            ->get("/patient/{$patientId}/chart/patient-summary");
        $response->assertOk();

        $props = $response->viewData('page')['props'];

        return [
            'restricted' => $props['chart']['restricted'],
            'unavailable' => $props['chart']['unavailable'],
            'allergies' => $props['chart']['allergies'],
        ];
    };

    $first = $restrictedFor((string) $withAllergy->id);
    $second = $restrictedFor((string) $withoutAllergy->id);

    // Gates are user+facility level: identical for both patients.
    expect($first['restricted'])->toBe($second['restricted'])
        ->and($first['unavailable'])->toBe($second['unavailable'])
        // Data differs per patient: one row vs none, never the other's rows.
        ->and($first['allergies'])->toHaveCount(1)
        ->and($second['allergies'])->toHaveCount(0);
});
