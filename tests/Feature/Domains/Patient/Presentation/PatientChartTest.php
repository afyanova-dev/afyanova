<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Patient\Presentation;

use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Organization\Domain\Model\Department;
use App\Domains\Organization\Domain\Model\Unit;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Scheduling\Application\Command\BookAppointment;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
});

it('composes the full chart with per-dashboard feeds', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'clinical.allergies.read',
        'clinical.problems.read',
        'clinical.procedures.read',
        'clinical.plans.read',
        'clinical.vitals.read',
        'pharmacy.access',
        'laboratory.access',
        'radiology.access',
        'sch.access',
        'care_coordination.access',
        'billing.access',
        'encounter.encounters.read',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $patient = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): Patient {
        $patient = Patient::factory()->forTenant($tenantId)->create([
            'given_name' => 'Amina',
            'family_name' => 'Juma',
            'display_name' => 'Amina Juma',
        ]);

        Allergy::factory()->forTenant($tenantId)->forPatient((string) $patient->id)->create([
            'facility_id' => $facilityId,
            'substance_name' => 'Penicillin',
        ]);

        VitalSignsObservation::factory()->forTenant($tenantId)->forPatient((string) $patient->id)->create([
            'facility_id' => $facilityId,
            'temperature' => 37.9,
            'heart_rate' => 52,
        ]);

        return $patient;
    });
    $patientId = (string) $patient->id;

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get("/patient/{$patientId}/chart/patient-summary")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Profile')
            ->where('patient.patientId', $patientId)
            ->has('chart.allergies', 1)
            ->where('chart.allergies.0.substance', 'Penicillin')
            ->has('chart.vitals', 1)
            ->where('chart.vitals.0.heartRate', 52)
            ->has('chart.conditions')
            ->has('chart.medications')
            ->has('chart.labOrders')
            ->has('chart.imagingOrders')
            ->has('chart.appointments')
            ->has('chart.programs')
            ->has('chart.procedures')
            ->has('chart.plans')
            ->has('chart.billing')
            ->where('chart.unavailable', [])
            ->where('chart.restricted', [])
        );
});

it('marks sections restricted instead of failing when permissions are missing', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);

    $patient = TenantContextScope::run(
        (string) $user->tenant_id,
        (string) $facility->id,
        fn (): Patient => Patient::factory()->forTenant((string) $user->tenant_id)->create([
            'given_name' => 'Amina',
            'family_name' => 'Juma',
            'display_name' => 'Amina Juma',
        ]),
    );
    $patientId = (string) $patient->id;

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get("/patient/{$patientId}/chart/allergies")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Profile')
            ->where('chart.allergies', [])
            ->whereContains('chart.restricted', 'allergies')
            ->whereContains('chart.restricted', 'visits')
            ->whereContains('chart.restricted', 'billing-history')
            ->where('chart.unavailable', [])
        );
});

it('returns 404 for an unknown patient on the chart route', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/patient/'.(string) Str::uuid7().'/chart/patient-summary')
        ->assertNotFound();
});

it('enriches chart appointments with type and resource display names', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'sch.access',
        'sch.appointment.book',
        'sch.resource.manage',
        'sch.appointment_type.manage',
        'hr.access',
        'hr.person.manage',
        'hr.staff.read',
        'hr.staff.manage',
    ]);
    $this->actingAs($user);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $patient = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId): Patient {
        return Patient::factory()->forTenant($tenantId)->create([
            'given_name' => 'Amina',
            'family_name' => 'Juma',
            'display_name' => 'Amina Juma',
        ]);
    });
    $patientId = (string) $patient->id;

    [$resourceId, $typeId] = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): array {
        $resourceId = createDoctorResource($tenantId, $facilityId, 'Asha', 'Mwinyi');
        $typeId = defineAppointmentTypeHelper($tenantId, 'GEN-CONSULT', 'General Consultation', 30, facilityId: $facilityId);

        return [$resourceId, $typeId];
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($patientId, $resourceId, $typeId): void {
        app(CommandBus::class)->dispatch(new BookAppointment(
            patientId: $patientId,
            resourceId: $resourceId,
            appointmentTypeId: $typeId,
            scheduledStart: CarbonImmutable::now()->addDay()->setTime(9, 0)->toIso8601String(),
            scheduledEnd: CarbonImmutable::now()->addDay()->setTime(9, 30)->toIso8601String(),
        ));
    });

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get("/patient/{$patientId}/chart/appointments")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Profile')
            ->has('chart.appointments', 1)
            ->where('chart.appointments.0.appointmentTypeId', $typeId)
            ->where('chart.appointments.0.resourceId', $resourceId)
            ->where('chart.appointments.0.appointmentTypeName', 'General Consultation')
            ->where('chart.appointments.0.resourceName', 'Dr. Asha Mwinyi'));
});

it('resolves appointment display names in bulk without per-row queries', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'sch.access',
        'sch.appointment.book',
        'sch.resource.manage',
        'sch.appointment_type.manage',
        'hr.access',
        'hr.person.manage',
        'hr.staff.read',
        'hr.staff.manage',
    ]);
    $this->actingAs($user);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $patient = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId): Patient {
        return Patient::factory()->forTenant($tenantId)->create([
            'given_name' => 'Amina',
            'family_name' => 'Juma',
            'display_name' => 'Amina Juma',
        ]);
    });
    $patientId = (string) $patient->id;

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $patientId): void {
        $resourceId = createDoctorResource($tenantId, $facilityId);
        $typeId = defineAppointmentTypeHelper($tenantId, 'GEN-CONSULT', 'General Consultation', 30, facilityId: $facilityId);
        foreach ([9, 10, 11] as $hour) {
            app(CommandBus::class)->dispatch(new BookAppointment(
                patientId: $patientId,
                resourceId: $resourceId,
                appointmentTypeId: $typeId,
                scheduledStart: CarbonImmutable::now()->addDay()->setTime($hour, 0)->toIso8601String(),
                scheduledEnd: CarbonImmutable::now()->addDay()->setTime($hour, 30)->toIso8601String(),
            ));
        }
    });

    DB::enableQueryLog();
    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get("/patient/{$patientId}/chart/appointments")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('chart.appointments', 3));
    $typeQueries = array_filter(
        DB::getQueryLog(),
        static fn (array $q): bool => str_contains((string) $q['query'], 'sch_appointment_types'),
    );
    $resourceQueries = array_filter(
        DB::getQueryLog(),
        static fn (array $q): bool => str_contains((string) $q['query'], 'sch_schedulable_resources'),
    );
    DB::disableQueryLog();

    // One batched lookup per catalogue — never one per appointment row.
    expect($typeQueries)->toHaveCount(1);
    expect($resourceQueries)->toHaveCount(1);
});

it('composes chart visits from encounter history with department names', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'encounter.encounters.read',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $patientId = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): string {
        $patient = Patient::factory()->forTenant($tenantId)->create([
            'given_name' => 'Amina',
            'family_name' => 'Juma',
            'display_name' => 'Amina Juma',
        ]);
        $patientId = (string) $patient->id;

        $department = Department::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'name' => 'Outpatient',
            'code' => 'OPD',
        ]);
        $unit = Unit::query()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'department_id' => $department->id,
            'name' => 'Room 1',
            'code' => 'R1',
            'unit_type' => 'GENERIC',
        ]);

        Encounter::factory()->forTenant($tenantId)->atFacility($facilityId)->forPatient($patientId)
            ->ofType(EncounterType::Emergency)->completed()
            ->create([
                'arrived_at' => now()->subDays(2),
                'completed_at' => now()->subDays(2)->addHour(),
            ]);
        Encounter::factory()->forTenant($tenantId)->atFacility($facilityId)->forPatient($patientId)
            ->ofType(EncounterType::Outpatient)->arrived()
            ->withLocation((string) $department->id, (string) $unit->id)
            ->create();

        // Another patient's encounter must not leak into this chart.
        $other = Patient::factory()->forTenant($tenantId)->create([
            'given_name' => 'Juma',
            'family_name' => 'Ali',
            'display_name' => 'Juma Ali',
        ]);
        Encounter::factory()->forTenant($tenantId)->atFacility($facilityId)
            ->forPatient((string) $other->id)->completed()->create();

        return $patientId;
    });

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get("/patient/{$patientId}/chart/visits")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Profile')
            ->has('chart.visits', 2)
            ->where('chart.visits.0.status', EncounterStatus::Arrived->value)
            ->where('chart.visits.0.encounterType', EncounterType::Outpatient->value)
            ->where('chart.visits.0.departmentName', 'Outpatient')
            ->where('chart.visits.0.unitName', 'Room 1')
            ->where('chart.visits.1.status', EncounterStatus::Completed->value)
            ->where('chart.visits.1.encounterType', EncounterType::Emergency->value)
            ->where('chart.unavailable', [])
            ->whereContains('chart.restricted', 'allergies')
            ->etc(function (Assert $page): void {
                expect($page->prop('chart.restricted'))->not->toContain('visits');
            }));
});
