<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Encounter\Presentation;

use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../../../../tests/Support/scheduling.php';
require_once __DIR__.'/../../../../../tests/Support/reception.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->targetDate = CarbonImmutable::now();
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function seedEncounterHelper(
    string $tenantId,
    string $facilityId,
    EncounterStatus $status,
    string $patientId,
    array $attributes = [],
): string {
    $arrivedAt = match ($status) {
        EncounterStatus::Planned => null,
        default => CarbonImmutable::now()->subMinutes(90),
    };
    $startedAt = match ($status) {
        EncounterStatus::Active, EncounterStatus::Completed, EncounterStatus::Abandoned, EncounterStatus::Transferred => CarbonImmutable::now()->subMinutes(60),
        default => null,
    };
    $completedAt = $status === EncounterStatus::Completed ? CarbonImmutable::now()->subMinutes(10) : null;
    $disposition = match ($status) {
        EncounterStatus::Completed => EncounterDisposition::Discharged->value,
        default => null,
    };

    return TenantContextScope::run($tenantId, null, fn () => (string) Encounter::factory()
        ->forTenant($tenantId)->atFacility($facilityId)
        ->forPatient($patientId)
        ->ofType(EncounterType::Outpatient)
        ->withStatus($status)
        ->create(array_merge([
            'arrived_at' => $arrivedAt,
            'started_at' => $startedAt,
            'admitted_at' => null,
            'completed_at' => $completedAt,
            'disposition' => $disposition,
        ], $attributes))->id);
}

function seedPatientHelper(string $tenantId, string $displayName): string
{
    return TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create([
            'display_name' => $displayName,
            'is_provisional' => false,
        ])->id);
}

it('renders the reception workspace with composed encounters, appointments, and metrics', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId1 = seedPatientHelper($tenantId, 'Amina Mohamed');
    $patientId2 = seedPatientHelper($tenantId, 'Baraka Juma');

    // Seed encounters: 1 ARRIVED, 1 ACTIVE, 1 COMPLETED
    seedEncounterHelper($tenantId, $facilityId, EncounterStatus::Arrived, $patientId1);
    seedEncounterHelper($tenantId, $facilityId, EncounterStatus::Active, $patientId2);
    seedEncounterHelper($tenantId, $facilityId, EncounterStatus::Completed, $patientId1);

    // Seed appointments as admin user: 1 BOOKED, 1 ARRIVED
    test()->actingAs($fx['user']);
    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'REC-OPD', 'Reception OPD', 30, facilityId: $facilityId);

    $app1Id = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: $patientId1,
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->setTime(9, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(9, 30)->toIso8601String(),
    );

    $app2Id = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: $patientId2,
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->setTime(10, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(10, 30)->toIso8601String(),
    );

    // Mark second appointment as ARRIVED
    TenantContextScope::run($tenantId, $facilityId, function () use ($app2Id): void {
        DB::table('sch_appointments')->where('id', $app2Id)->update(['status' => 'ARRIVED']);
    });

    // Create user with Reception permissions: encounter.encounters.read, sch.access, patient.patients.read
    $receptionUser = createReceptionUser($tenantId, $facilityId, [
        'encounter.encounters.read',
        'sch.access',
        'patient.patients.read',
        'queue.ticket.view',
    ]);

    $response = $this->actingAs($receptionUser)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/reception');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->where('facilityId', $facilityId)
            ->where('date', $this->targetDate->toDateString())
            ->has('encounters', 3)
            ->has('appointments', 2)
            ->has('referrals')
            ->where('restricted', [])
            ->where('rosterStatus', 'ALL')
            ->where('rosterFilter', '')
            ->where('metrics.totalEncounters', 3)
            ->where('metrics.waitingEncounters', 1)
            ->where('metrics.inServiceEncounters', 1)
            ->where('metrics.inServicePatients', 0)
            ->where('metrics.activeAttendanceEncounters', 2)
            ->where('metrics.completedEncounters', 1)
            ->where('metrics.totalAppointments', 2)
            ->where('metrics.expectedAppointments', 1)
            ->where('metrics.arrivedAppointments', 1)
            ->where('metrics.inServiceAppointments', 0)
            ->where('metrics.completedAppointments', 0)
            ->where('encounters.0.patient.displayName', fn ($name) => in_array($name, ['Amina Mohamed', 'Baraka Juma'], true))
            ->where('appointments.0.patient.displayName', fn ($name) => in_array($name, ['Amina Mohamed', 'Baraka Juma'], true))
            ->where('appointments.0.appointmentTypeName', 'Reception OPD')
            ->where('appointments.0.resourceId', $resourceId)
        );
});

it('redirects unauthenticated guest to login', function (): void {
    $this->get('/workspaces/reception')
        ->assertRedirect('/login');
});

it('restricts the encounters section without encounter.encounters.read', function (): void {
    $fx = schedulingFixture();
    $receptionUser = createReceptionUser((string) $fx['tenant']->id, (string) $fx['facility']->id, [
        'sch.access',
        'patient.patients.read',
    ]);

    $this->actingAs($receptionUser)
        ->withSession(['active_facility_id' => (string) $fx['facility']->id])
        ->get('/workspaces/reception')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->where('restricted', fn ($restricted) => $restricted->contains('encounters')
                && ! $restricted->contains('appointments')
                && ! $restricted->contains('patients'))
            ->where('encounters', [])
            ->has('appointments')
        );
});

it('restricts the appointments section without sch.access', function (): void {
    $fx = schedulingFixture();
    $receptionUser = createReceptionUser((string) $fx['tenant']->id, (string) $fx['facility']->id, [
        'encounter.encounters.read',
        'patient.patients.read',
    ]);

    $this->actingAs($receptionUser)
        ->withSession(['active_facility_id' => (string) $fx['facility']->id])
        ->get('/workspaces/reception')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->where('restricted', fn ($restricted) => $restricted->contains('appointments')
                && ! $restricted->contains('encounters')
                && ! $restricted->contains('patients'))
            ->where('appointments', [])
            ->has('encounters')
        );
});

it('restricts the patient section without patient.patients.read', function (): void {
    $fx = schedulingFixture();
    $receptionUser = createReceptionUser((string) $fx['tenant']->id, (string) $fx['facility']->id, [
        'encounter.encounters.read',
        'sch.access',
    ]);

    $this->actingAs($receptionUser)
        ->withSession(['active_facility_id' => (string) $fx['facility']->id])
        ->get('/workspaces/reception?patient_id='.Str::uuid())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->where('restricted', fn ($restricted) => $restricted->contains('patients')
                && ! $restricted->contains('encounters')
                && ! $restricted->contains('appointments'))
            ->where('preselectedPatient', null)
            ->where('searchResults', null)
        );
});

it('forbids a user with none of the front-desk section permissions', function (): void {
    $fx = schedulingFixture();
    $receptionUser = createReceptionUser((string) $fx['tenant']->id, (string) $fx['facility']->id, [
        'queue.ticket.view',
    ]);

    $this->actingAs($receptionUser)
        ->withSession(['active_facility_id' => (string) $fx['facility']->id])
        ->get('/workspaces/reception')
        ->assertForbidden();
});

it('forbids a user without active facility context', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $orgId = (string) $fx['organization']->id;

    $user = createReceptionUser($tenantId, (string) $fx['facility']->id, [
        'encounter.encounters.read',
        'sch.access',
        'patient.patients.read',
    ]);

    // Give user access to a second facility in the same tenant so neither is auto-selected
    TenantContextScope::run($tenantId, null, function () use ($tenantId, $orgId, $user): void {
        $fac2 = Facility::factory()->forTenant($tenantId, $orgId)->create();
        $roleId = DB::table('idn_roles')->where('tenant_id', $tenantId)->value('id');
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => (string) $user->id,
            'role_id' => $roleId,
            'facility_id' => (string) $fac2->id,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($user)
        ->get('/workspaces/reception')
        ->assertForbidden();
});

it('rejects POST to reception workspace with 405 Method Not Allowed', function (): void {
    $fx = schedulingFixture();
    $user = createReceptionUser((string) $fx['tenant']->id, (string) $fx['facility']->id, [
        'encounter.encounters.read',
        'sch.access',
        'patient.patients.read',
    ]);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $fx['facility']->id])
        ->post('/workspaces/reception')
        ->assertStatus(405);
});

it('isolates encounters and appointments by active facility', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $orgId = (string) $fx['organization']->id;
    $facilityAId = (string) $fx['facility']->id;

    // Create Facility B in same tenant
    $facB = TenantContextScope::run($tenantId, null, fn () => Facility::factory()->forTenant($tenantId, $orgId)->create());
    $facilityBId = (string) $facB->id;

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $fx, $facilityBId): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $fx['user']->id,
            'role_id' => $fx['role_id'],
            'facility_id' => $facilityBId,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $patientId = seedPatientHelper($tenantId, 'Patient AB');

    // Seed encounter in Facility A and another in Facility B
    seedEncounterHelper($tenantId, $facilityAId, EncounterStatus::Arrived, $patientId);
    seedEncounterHelper($tenantId, $facilityBId, EncounterStatus::Arrived, $patientId);

    // Seed appointment in Facility A and another in Facility B
    test()->actingAs($fx['user']);
    $resA = createDoctorResource($tenantId, $facilityAId);
    $resB = createDoctorResource($tenantId, $facilityBId);
    $typeA = defineAppointmentTypeHelper($tenantId, 'TYP-A', 'Type A', 30, facilityId: $facilityAId);
    $typeB = defineAppointmentTypeHelper($tenantId, 'TYP-B', 'Type B', 30, facilityId: $facilityBId);

    bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityAId,
        patientId: $patientId,
        resourceId: $resA,
        appointmentTypeId: $typeA,
        scheduledStart: $this->targetDate->setTime(9, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(9, 30)->toIso8601String(),
    );

    bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityBId,
        patientId: $patientId,
        resourceId: $resB,
        appointmentTypeId: $typeB,
        scheduledStart: $this->targetDate->setTime(9, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(9, 30)->toIso8601String(),
    );

    $userA = createReceptionUser($tenantId, $facilityAId, [
        'encounter.encounters.read',
        'sch.access',
        'patient.patients.read',
    ]);

    // Query Facility A
    $response = $this->actingAs($userA)
        ->withSession(['active_facility_id' => $facilityAId])
        ->get('/workspaces/reception');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->where('facilityId', $facilityAId)
            ->has('encounters', 1)
            ->where('encounters.0.facilityId', $facilityAId)
            ->has('appointments', 1)
            ->where('appointments.0.facilityId', $facilityAId)
            ->where('metrics.totalEncounters', 1)
            ->where('metrics.totalAppointments', 1)
        );
});

it('filters today attendances by encounter status on the server', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId1 = seedPatientHelper($tenantId, 'Amina Mohamed');
    $patientId2 = seedPatientHelper($tenantId, 'Baraka Juma');
    seedEncounterHelper($tenantId, $facilityId, EncounterStatus::Arrived, $patientId1);
    seedEncounterHelper($tenantId, $facilityId, EncounterStatus::Active, $patientId2);
    seedEncounterHelper($tenantId, $facilityId, EncounterStatus::Completed, $patientId1);

    test()->actingAs($fx['user']);
    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'REC-FILTER', 'Reception filter', 30, facilityId: $facilityId);
    $appointmentId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: $patientId1,
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->setTime(9, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(9, 30)->toIso8601String(),
    );
    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId): void {
        DB::table('sch_appointments')->where('id', $appointmentId)->update(['status' => 'COMPLETED']);
    });
    $bookedAppointmentId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: $patientId2,
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->setTime(10, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(10, 30)->toIso8601String(),
    );

    $receptionUser = createReceptionUser($tenantId, $facilityId, [
        'encounter.encounters.read',
        'sch.access',
        'patient.patients.read',
    ]);

    $this->actingAs($receptionUser)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/reception?status=ARRIVED')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->has('encounters', 1)
            ->where('encounters.0.status', EncounterStatus::Arrived->value)
            ->where('rosterStatus', 'ARRIVED')
            ->where('metrics.waitingEncounters', 1)
            ->where('metrics.inServiceEncounters', 1)
        );

    $this->get('/workspaces/reception?status=ACTIVE%2CARRIVED')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->has('encounters', 2)
            ->where('encounters', fn ($items) => collect($items)->pluck('status')->sort()->values()->all() === ['ACTIVE', 'ARRIVED'])
            ->has('appointments', 0)
            ->where('rosterStatus', 'ARRIVED,ACTIVE')
        );

    $this->get('/workspaces/reception?status=COMPLETED')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->has('encounters', 1)
            ->where('encounters.0.status', EncounterStatus::Completed->value)
            ->has('appointments', 1)
            ->where('appointments.0.status', 'COMPLETED')
            ->where('rosterStatus', 'COMPLETED')
        );

    $this->get('/workspaces/reception?status=BOOKED')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->has('encounters', 0)
            ->has('appointments', 1)
            ->where('appointments.0.id', $bookedAppointmentId)
            ->where('appointments.0.status', 'BOOKED')
            ->where('rosterStatus', 'BOOKED')
        );
});

it('filters today attendances by patient name on the server', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId1 = seedPatientHelper($tenantId, 'Amina Mohamed');
    $patientId2 = seedPatientHelper($tenantId, 'Baraka Juma');
    seedEncounterHelper($tenantId, $facilityId, EncounterStatus::Arrived, $patientId1);
    seedEncounterHelper($tenantId, $facilityId, EncounterStatus::Active, $patientId2);

    $receptionUser = createReceptionUser($tenantId, $facilityId, [
        'encounter.encounters.read',
        'sch.access',
        'patient.patients.read',
    ]);

    $this->actingAs($receptionUser)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/reception?filter=Amina')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->has('encounters', 1)
            ->where('encounters.0.patient.displayName', 'Amina Mohamed')
            ->where('rosterFilter', 'Amina')
        );
});

it('includes ongoing active encounters from previous dates in the reception workspace', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId = seedPatientHelper($tenantId, 'Halima Alli');

    // Seed active encounter that arrived yesterday (Invariant 12b active attendance)
    $facilityTimeZone = (string) config('app.facility_timezone', 'Africa/Dar_es_Salaam');
    $yesterday = CarbonImmutable::now($facilityTimeZone)->subDays(1);
    seedEncounterHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        status: EncounterStatus::Arrived,
        patientId: $patientId,
        attributes: [
            'arrived_at' => $yesterday->setTime(12, 0),
            'created_at' => $yesterday->setTime(12, 0),
        ],
    );

    $receptionUser = createReceptionUser($tenantId, $facilityId, [
        'encounter.encounters.read',
        'sch.access',
        'patient.patients.read',
    ]);

    $response = $this->actingAs($receptionUser)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/reception');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->has('encounters', 1)
            ->where('encounters.0.patient.displayName', 'Halima Alli')
            ->where('encounters.0.status', EncounterStatus::Arrived->value)
            ->where('metrics.waitingEncounters', 0)
            ->where('metrics.activeAttendanceEncounters', 0)
        );
});

it('passes preselectedPatient when patient_id query parameter is provided and patient has no active encounter', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId = seedPatientHelper($tenantId, 'Zuwena Said');

    $receptionUser = createReceptionUser($tenantId, $facilityId, [
        'encounter.encounters.read',
        'sch.access',
        'patient.patients.read',
    ]);

    $response = $this->actingAs($receptionUser)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/reception?patient_id='.$patientId);

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->where('preselectedPatient.patientId', $patientId)
            ->where('preselectedPatient.displayName', 'Zuwena Said')
            ->where('activeEncounter', null)
        );
});

it('passes both preselectedPatient and activeEncounter when patient_id has an active encounter at facility', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId = seedPatientHelper($tenantId, 'Zuwena Said');

    $encounterId = seedEncounterHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        status: EncounterStatus::Arrived,
        patientId: $patientId,
    );

    $receptionUser = createReceptionUser($tenantId, $facilityId, [
        'encounter.encounters.read',
        'sch.access',
        'patient.patients.read',
    ]);

    $response = $this->actingAs($receptionUser)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/reception?patient_id='.$patientId);

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reception/Index')
            ->where('preselectedPatient.patientId', $patientId)
            ->where('activeEncounter.encounterId', $encounterId)
            ->where('activeEncounter.status', EncounterStatus::Arrived->value)
        );
});
