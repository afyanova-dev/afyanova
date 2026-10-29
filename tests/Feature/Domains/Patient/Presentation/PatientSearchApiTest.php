<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Patient\Presentation;

use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientRegistration;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('requires authentication for patient search api', function (): void {
    $this->getJson('/workspaces/patient/api/search?q=Joshua')
        ->assertStatus(401);
});

it('requires patient.patients.read permission', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['encounter.encounters.read']);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->getJson('/workspaces/patient/api/search?q=Joshua')
        ->assertStatus(403);
});

it('protects the Patients Inertia page with authentication and patient read permission', function (): void {
    $this->get('/workspaces/patient')->assertRedirect('/login');

    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['encounter.encounters.read']);
    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/patient')
        ->assertForbidden();
});

it('renders paginated browse and search snapshots on the Patients Inertia page', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): void {
        $older = Patient::factory()->forTenant($tenantId)->create([
            'patient_number' => 'PAT-100020A',
            'given_name' => 'Older',
            'family_name' => 'Patient',
            'display_name' => 'Older Patient',
            'sex' => Sex::Female,
            'created_at' => now()->subDay(),
        ]);
        $newer = Patient::factory()->forTenant($tenantId)->create([
            'patient_number' => 'PAT-100021B',
            'given_name' => 'Newer',
            'family_name' => 'Patient',
            'display_name' => 'Newer Patient',
            'sex' => Sex::Male,
            'created_at' => now(),
        ]);
        foreach ([$older, $newer] as $patient) {
            PatientRegistration::factory()->forTenant($tenantId)->create([
                'patient_id' => $patient->id,
                'facility_id' => $facilityId,
            ]);
        }
    });

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?page=2&per_page=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->where('mode', 'browse')
            ->where('q', '')
            ->where('total', 2)
            ->where('currentPage', 2)
            ->where('perPage', 1)
            ->has('patients', 1)
            ->where('patients.0.patientNumber', 'PAT-100020A')
        );

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?q=Newer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->where('mode', 'search')
            ->where('q', 'Newer')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Newer Patient')
        );
});

it('passes too-broad search criteria to the Patients page as an error, not an empty browse', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/patient?q=AB')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->where('mode', 'search')
            ->where('searchError', 'A name-fragment search needs at least 3 characters.')
            ->has('patients', 0)
        );
});

it('returns search results matching patient name fragment', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'encounter.encounters.read',
    ]);

    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): void {
        $p1 = Patient::factory()->forTenant($tenantId)->create([
            'patient_number' => 'PAT-100008E',
            'given_name' => 'Joshua',
            'family_name' => 'Johnson',
            'display_name' => 'Joshua Johnson',
            'sex' => Sex::Male,
        ]);
        PatientRegistration::factory()->forTenant($tenantId)->create([
            'patient_id' => $p1->id,
            'facility_id' => $facilityId,
        ]);

        $p2 = Patient::factory()->forTenant($tenantId)->create([
            'patient_number' => 'PAT-100009F',
            'given_name' => 'Asha',
            'family_name' => 'Mkwawa',
            'display_name' => 'Asha Mkwawa',
            'sex' => Sex::Female,
        ]);
        PatientRegistration::factory()->forTenant($tenantId)->create([
            'patient_id' => $p2->id,
            'facility_id' => $facilityId,
        ]);
    });

    it('filters search results to registrations at the active facility', function (): void {
        ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
            'patient.patients.read',
        ]);
        $tenantId = (string) $tenant->id;
        $facilityId = (string) $facility->id;
        $otherFacilityId = (string) TenantContextScope::run($tenantId, null, fn () => Facility::factory()
            ->forTenant($tenantId, (string) $organization->id)->create()->id);

        TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $otherFacilityId): void {
            foreach ([
                ['facilityId' => $facilityId, 'number' => 'PAT-100030A', 'name' => 'Scoped Local'],
                ['facilityId' => $otherFacilityId, 'number' => 'PAT-100031B', 'name' => 'Scoped Remote'],
            ] as $fixture) {
                $patient = Patient::factory()->forTenant($tenantId)->create([
                    'patient_number' => $fixture['number'],
                    'given_name' => 'Scoped',
                    'family_name' => $fixture['name'],
                    'display_name' => $fixture['name'],
                    'sex' => Sex::Female,
                ]);
                PatientRegistration::factory()->forTenant($tenantId)->create([
                    'patient_id' => $patient->id,
                    'facility_id' => $fixture['facilityId'],
                ]);
            }
        });

        $this->actingAs($user)
            ->withSession(['active_facility_id' => $facilityId])
            ->getJson('/workspaces/patient/api/search?q=Scoped&facility_id='.$otherFacilityId)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('patients.0.displayName', 'Scoped Local');
    });

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->getJson('/workspaces/patient/api/search?q=Joshua')
        ->assertOk()
        ->json();

    expect($response)->toHaveKeys(['patients', 'total'])
        ->and($response['patients'])->toHaveCount(1)
        ->and($response['patients'][0]['displayName'])->toBe('Joshua Johnson')
        ->and($response['patients'][0]['patientNumber'])->toBe('PAT-100008E');
});

it('returns a paginated active-facility registration page when search criteria are blank', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
    ]);

    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $otherFacilityId = (string) TenantContextScope::run($tenantId, null, fn () => Facility::factory()
        ->forTenant($tenantId, (string) $organization->id)->create()->id);

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $otherFacilityId): void {
        $older = Patient::factory()->forTenant($tenantId)->create([
            'patient_number' => 'PAT-100020A',
            'given_name' => 'Older',
            'family_name' => 'Patient',
            'display_name' => 'Older Patient',
            'sex' => Sex::Female,
            'created_at' => now()->subDay(),
        ]);
        $newer = Patient::factory()->forTenant($tenantId)->create([
            'patient_number' => 'PAT-100021B',
            'given_name' => 'Newer',
            'family_name' => 'Patient',
            'display_name' => 'Newer Patient',
            'sex' => Sex::Male,
            'created_at' => now(),
        ]);
        foreach ([$older, $newer] as $patient) {
            PatientRegistration::factory()->forTenant($tenantId)->create([
                'patient_id' => $patient->id,
                'facility_id' => $facilityId,
            ]);
        }
        $remote = Patient::factory()->forTenant($tenantId)->create([
            'patient_number' => 'PAT-100022C',
            'given_name' => 'Remote',
            'family_name' => 'Patient',
            'display_name' => 'Remote Patient',
            'sex' => Sex::Male,
            'created_at' => now()->addDay(),
        ]);
        PatientRegistration::factory()->forTenant($tenantId)->create([
            'patient_id' => $remote->id,
            'facility_id' => $otherFacilityId,
        ]);
    });

    $request = $this->actingAs($user)->withSession(['active_facility_id' => $facilityId]);

    foreach (['', '?q=%20%20'] as $query) {
        $response = $request->getJson('/workspaces/patient/api/search'.$query)
            ->assertOk()
            ->json();

        expect($response)->toHaveKeys(['patients', 'total', 'currentPage', 'lastPage'])
            ->and($response['total'])->toBe(2)
            ->and($response['patients'][0]['patientNumber'])->toBe('PAT-100021B');
    }
});

it('routes a one-character name query to recent patients and reports a two-character name error', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);
    $request = $this->actingAs($user)->withSession(['active_facility_id' => (string) $facility->id]);

    $request->getJson('/workspaces/patient/api/search?q=A')
        ->assertOk()
        ->assertJsonMissingPath('error');

    $request->getJson('/workspaces/patient/api/search?q=AB')
        ->assertOk()
        ->assertJsonPath('patients', [])
        ->assertJsonPath('total', 0)
        ->assertJsonPath('error', 'A name-fragment search needs at least 3 characters.');
});

it('enriches search results with active encounter when present', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'encounter.encounters.read',
    ]);

    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): void {
        $patient = Patient::factory()->forTenant($tenantId)->create([
            'patient_number' => 'PAT-100008E',
            'given_name' => 'Joshua',
            'family_name' => 'Johnson',
            'display_name' => 'Joshua Johnson',
            'sex' => Sex::Male,
        ]);
        PatientRegistration::factory()->forTenant($tenantId)->create([
            'patient_id' => $patient->id,
            'facility_id' => $facilityId,
        ]);

        Encounter::factory()->forTenant($tenantId)->create([
            'patient_id' => $patient->id,
            'facility_id' => $facilityId,
            'encounter_number' => 'ENC-202609-0012',
            'encounter_type' => EncounterType::Outpatient,
            'status' => EncounterStatus::Arrived,
            'arrived_at' => now(),
        ]);
    });

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->getJson('/workspaces/patient/api/search?q=Joshua')
        ->assertOk()
        ->json();

    expect($response['patients'][0]['activeEncounter'])->not->toBeNull()
        ->and($response['patients'][0]['activeEncounter']['encounterNumber'])->toBe('ENC-202609-0012')
        ->and($response['patients'][0]['activeEncounter']['status'])->toBe('ARRIVED');
});

it('returns estimated age alongside formatted dob', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'encounter.encounters.read',
    ]);

    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): void {
        $patient = Patient::factory()->forTenant($tenantId)->create([
            'patient_number' => 'PAT-100010G',
            'given_name' => 'Zawadi',
            'family_name' => 'Juma',
            'display_name' => 'Zawadi Juma',
            'sex' => Sex::Female,
            'date_of_birth' => '1990-05-01',
        ]);
        PatientRegistration::factory()->forTenant($tenantId)->create([
            'patient_id' => $patient->id,
            'facility_id' => $facilityId,
        ]);
    });

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->getJson('/workspaces/patient/api/search?q=Zawadi')
        ->assertOk()
        ->json();

    expect($response['patients'])->toHaveCount(1)
        ->and($response['patients'][0]['formattedDob'])->toBe('01-May-1990')
        ->and($response['patients'][0]['age'])->not->toBeNull()
        ->and($response['patients'][0]['age'])->toMatch('/\d+ yrs/');
});
