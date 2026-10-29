<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Scheduling\Application\Command\BookAppointment;
use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Domains\Scheduling\Domain\ValueObject\AppointmentStatus;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('composes today widgets for a permitted user', function (): void {
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
        'encounter.encounters.read',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $this->actingAs($user);

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): void {
        $patient = Patient::factory()->forTenant($tenantId)->create([
            'given_name' => 'Amina',
            'family_name' => 'Juma',
            'display_name' => 'Amina Juma',
        ]);
        $patientId = (string) $patient->id;

        $resourceId = createDoctorResource($tenantId, $facilityId, 'Asha', 'Mwinyi');
        $typeId = defineAppointmentTypeHelper($tenantId, 'GEN-CONSULT', 'General Consultation', 30, facilityId: $facilityId);
        $sameDayStart = CarbonImmutable::now()->startOfDay()->addHours(14);
        app(CommandBus::class)->dispatch(new BookAppointment(
            patientId: $patientId,
            resourceId: $resourceId,
            appointmentTypeId: $typeId,
            scheduledStart: $sameDayStart->toIso8601String(),
            scheduledEnd: $sameDayStart->addMinutes(30)->toIso8601String(),
            facilityId: $facilityId,
        ));
        foreach ([
            AppointmentStatus::CANCELLED,
            AppointmentStatus::RESCHEDULED,
            AppointmentStatus::NO_SHOW,
            AppointmentStatus::COMPLETED,
        ] as $index => $status) {
            $appointmentId = (string) Str::uuid7();
            $start = CarbonImmutable::now()->startOfDay()->addHours(8 + $index);
            app(CommandBus::class)->dispatch(new BookAppointment(
                patientId: $patientId,
                resourceId: $resourceId,
                appointmentTypeId: $typeId,
                scheduledStart: $start->toIso8601String(),
                scheduledEnd: $start->addMinutes(30)->toIso8601String(),
                facilityId: $facilityId,
                appointmentId: $appointmentId,
            ));
            Appointment::query()->whereKey($appointmentId)->update(['status' => $status->value]);
        }

        Encounter::factory()->forTenant($tenantId)->atFacility($facilityId)->forPatient($patientId)
            ->ofType(EncounterType::Outpatient)->arrived()->create();
        Encounter::factory()->forTenant($tenantId)->atFacility($facilityId)->forPatient($patientId)
            ->ofType(EncounterType::Emergency)->completed()->create();
    });

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->has('widgets', 4)
            ->where('widgets.0.id', 'hr-assigned-staff')
            ->where('widgets.0.count', 0)
            ->where('widgets.1.id', 'scheduled-today')
            ->where('widgets.1.count', 2)
            ->where('widgets.2.id', 'arrived-today')
            ->where('widgets.2.count', 2)
            ->where('widgets.3.id', 'active-visits')
            ->where('widgets.3.count', 1)
            ->whereContains('restricted', 'triage-queue')
            ->whereContains('restricted', 'consultation-queue')
            ->whereContains('restricted', 'prescriptions-to-review')
            ->whereContains('restricted', 'pharmacy-orders-to-process')
            ->whereContains('restricted', 'my-cash-session')
            ->whereContains('restricted', 'pending-billing-events')
            ->where('unavailable', []));
});

it('counts only today arrivals and active visits without a result limit', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'encounter.encounters.read',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): void {
        for ($index = 0; $index < 201; $index++) {
            $patient = Patient::factory()->forTenant($tenantId)->create();
            Encounter::factory()->forTenant($tenantId)->atFacility($facilityId)
                ->forPatient((string) $patient->id)
                ->arrived()
                ->create();
        }

        $activePatient = Patient::factory()->forTenant($tenantId)->create();
        Encounter::factory()->forTenant($tenantId)->atFacility($facilityId)
            ->forPatient((string) $activePatient->id)
            ->active()
            ->create();

        $completedPatient = Patient::factory()->forTenant($tenantId)->create();
        Encounter::factory()->forTenant($tenantId)->atFacility($facilityId)
            ->forPatient((string) $completedPatient->id)
            ->completed()
            ->create();

        $plannedPatient = Patient::factory()->forTenant($tenantId)->create();
        Encounter::factory()->forTenant($tenantId)->atFacility($facilityId)
            ->forPatient((string) $plannedPatient->id)
            ->planned()
            ->create();

        $oldActivePatient = Patient::factory()->forTenant($tenantId)->create();
        Encounter::factory()->forTenant($tenantId)->atFacility($facilityId)
            ->forPatient((string) $oldActivePatient->id)
            ->active()
            ->state(['arrived_at' => CarbonImmutable::now()->subDay()])
            ->create();
    });

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->has('widgets', 2)
            ->where('widgets.0.id', 'arrived-today')
            ->where('widgets.0.count', 203)
            ->where('widgets.1.id', 'active-visits')
            ->where('widgets.1.count', 202)
            ->where('unavailable', []));
});

it('restricts widgets instead of failing when permissions are missing', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('widgets', [])
            ->whereContains('restricted', 'scheduled-today')
            ->whereContains('restricted', 'arrived-today')
            ->whereContains('restricted', 'active-visits')
            ->where('unavailable', []));
});

it('renders zero counts when nothing is scheduled', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'sch.access',
        'encounter.encounters.read',
    ]);
    $tenantId = (string) $facility->tenant_id;
    $facilityId = (string) $facility->id;

    $triageQueue = queueTestQueue($tenantId, $facilityId, 'TRIAGE');
    $consultationQueue = queueTestQueue($tenantId, $facilityId, 'CONSULT');
    TenantContextScope::run($tenantId, $facilityId, function () use ($triageQueue, $consultationQueue): void {
        Queue::query()->whereKey($triageQueue->id)->update([
            'service_capability' => ServiceCapability::TRIAGE->value,
        ]);
        Queue::query()->whereKey($consultationQueue->id)->update([
            'service_capability' => ServiceCapability::CONSULTATION->value,
        ]);
    });
    $triageEntry = queueTestEntry($tenantId, $facilityId, (string) $triageQueue->id);
    queueTestEntry($tenantId, $facilityId, (string) $triageQueue->id);
    queueTestEntry($tenantId, $facilityId, (string) $consultationQueue->id);
    TenantContextScope::run($tenantId, $facilityId, function () use ($triageEntry): void {
        QueueEntry::query()->whereKey($triageEntry->id)->update([
            'status' => QueueStatus::COMPLETED->value,
        ]);
    });

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->has('widgets', 3)
            ->where('widgets.0.count', 0)
            ->where('widgets.1.count', 0)
            ->where('widgets.2.count', 0)
            ->whereContains('restricted', 'triage-queue')
            ->whereContains('restricted', 'consultation-queue')
            ->whereContains('restricted', 'prescriptions-to-review')
            ->whereContains('restricted', 'pharmacy-orders-to-process')
            ->whereContains('restricted', 'my-cash-session')
            ->whereContains('restricted', 'pending-billing-events')
            ->where('unavailable', []));
});

it('composes work queue summaries only for permissions held by the user', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'queue.ticket.view',
        'clinical.access',
        'clinical.triage.record',
        'patient.patients.read',
        'pharmacy.access',
        'pharmacy.prescriptions.accept',
        'pharmacy.dispensing.record',
        'billing.access',
        'billing.cash_sessions.manage',
        'billing.charges.manage',
    ]);

    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $consultationRoleId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, function () use (
        $tenantId,
        $facilityId,
        $user,
        $consultationRoleId,
    ): void {
        DB::table('idn_roles')->insert([
            'id' => $consultationRoleId,
            'tenant_id' => $tenantId,
            'key' => 'test_consultation_role',
            'name' => 'Test Consultation Role',
            'status' => 'ACTIVE',
            'scope_type' => 'FACILITY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $permissionId = DB::table('idn_permissions')->where('key', 'clinical.consultations.read')->value('id')
            ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                'id' => $id,
                'key' => 'clinical.consultations.read',
                'layer' => 'ACTION',
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        DB::table('idn_permission_role')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'role_id' => $consultationRoleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'role_id' => $consultationRoleId,
            'facility_id' => $facilityId,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $triageQueue = queueTestQueue($tenantId, $facilityId, 'TRIAGE');
    $consultationQueue = queueTestQueue($tenantId, $facilityId, 'CONSULT');

    TenantContextScope::run($tenantId, $facilityId, function () use ($triageQueue, $consultationQueue): void {
        Queue::query()->whereKey($triageQueue->id)->update([
            'service_capability' => ServiceCapability::TRIAGE->value,
        ]);
        Queue::query()->whereKey($consultationQueue->id)->update([
            'service_capability' => ServiceCapability::CONSULTATION->value,
        ]);
    });

    queueTestEntry($tenantId, $facilityId, (string) $triageQueue->id);
    queueTestEntry($tenantId, $facilityId, (string) $consultationQueue->id);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->has('widgets', 6)
            ->where('widgets.0.id', 'triage-queue')
            ->where('widgets.0.section', 'work')
            ->where('widgets.0.count', 1)
            ->where('widgets.1.id', 'consultation-queue')
            ->where('widgets.1.count', 1)
            ->where('widgets.2.id', 'prescriptions-to-review')
            ->where('widgets.2.count', 0)
            ->where('widgets.3.id', 'pharmacy-orders-to-process')
            ->where('widgets.3.count', 0)
            ->where('widgets.4.id', 'my-cash-session')
            ->where('widgets.4.count', 0)
            ->where('widgets.5.id', 'pending-billing-events')
            ->where('widgets.5.count', 0)
            ->where('unavailable', []));
});

it('keeps queue summaries scoped to the active facility', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = makeTenantWithUser([
        'queue.ticket.view',
        'clinical.access',
        'clinical.triage.record',
        'patient.patients.read',
    ]);

    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $otherFacility = queueTestSecondFacility($tenantId, (string) $organization->id, (string) $user->id, $roleId);

    $triageQueue = queueTestQueue($tenantId, $facilityId, 'TRIAGE');
    $otherFacilityTriageQueue = queueTestQueue($tenantId, (string) $otherFacility->id, 'TRIAGE');
    queueTestEntry($tenantId, $facilityId, (string) $triageQueue->id);
    queueTestEntry($tenantId, (string) $otherFacility->id, (string) $otherFacilityTriageQueue->id);
    queueTestEntry($tenantId, (string) $otherFacility->id, (string) $otherFacilityTriageQueue->id);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('widgets.0.id', 'triage-queue')
            ->where('widgets.0.count', 1)
            ->where('unavailable', []));
});

it('shows facility-scoped HR dashboard summaries to staff managers', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'hr.person.manage',
        'hr.staff.read',
        'hr.staff.manage',
        'hr.assignment.manage',
        'hr.staff_user_link.manage',
    ]);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('widgets.0.id', 'hr-assigned-staff')
            ->where('widgets.0.title', 'Assigned staff')
            ->where('widgets.0.count', 0)
            ->where('widgets.0.href', route('hr.staff.index'))
            ->where('widgets.1.id', 'hr-pending-assignment-requests')
            ->where('widgets.1.title', 'Staff placements to review')
            ->where('widgets.1.count', 0)
            ->where('widgets.1.href', route('hr.staff.onboarding', ['focus' => 'placements']))
            ->where('widgets.2.id', 'hr-pending-access-requests')
            ->where('widgets.2.title', 'Role approvals to review')
            ->where('widgets.2.count', 0)
            ->where('widgets.2.href', route('hr.staff.onboarding', ['focus' => 'access']))
            ->where('widgets.3.id', 'hr-expired-invitations')
            ->where('widgets.3.title', 'Expired invitations')
            ->where('widgets.3.count', 0)
            ->where('widgets.3.href', route('hr.staff.onboarding', ['focus' => 'invitations']))
            ->where('quickActions.0.id', 'hr-add-staff')
            ->where('quickActions.0.href', route('hr.staff.create')));
});

it('shows only supported dashboard summaries for each workspace persona', function (): void {
    $personas = [
        'Receptionist' => [
            ['sch.access', 'encounter.encounters.read'],
            ['scheduled-today', 'arrived-today', 'active-visits'],
        ],
        'Nurse' => [
            ['queue.ticket.view', 'clinical.access', 'clinical.triage.record', 'patient.patients.read'],
            ['triage-queue'],
        ],
        'Clinician' => [
            ['queue.ticket.view', 'clinical.access', 'clinical.consultations.read', 'patient.patients.read'],
            ['consultation-queue'],
        ],
        'Pharmacist' => [
            ['pharmacy.access', 'pharmacy.prescriptions.accept', 'pharmacy.dispensing.record'],
            ['prescriptions-to-review', 'pharmacy-orders-to-process'],
        ],
        'Cashier' => [
            ['billing.access', 'billing.cash_sessions.manage'],
            ['my-cash-session'],
        ],
        'Billing' => [
            ['billing.access', 'billing.charges.manage'],
            ['pending-billing-events'],
        ],
        'Laboratory Technician' => [
            ['laboratory.access'],
            [],
        ],
    ];

    foreach ($personas as $persona => [$permissions, $expectedWidgetIds]) {
        ['facility' => $facility, 'user' => $user] = makeTenantWithUser($permissions);

        $this->actingAs($user)
            ->withSession(['active_facility_id' => (string) $facility->id])
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Index')
                ->where('widgets', static fn (Collection $widgets): bool => $widgets->pluck('id')->all() === $expectedWidgetIds)
                ->where('unavailable', []));
    }
});

it('composes quick actions from held permissions', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'patient.patients.create',
        'encounter.encounters.create',
        'sch.appointment.book',
    ]);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->has('quickActions', 3)
            ->where('quickActions.0.id', 'register-patient')
            ->where('quickActions.0.href', route('patient.register', ['after_registration' => 'FRONT_DESK']))
            ->where('quickActions.1.id', 'check-in-walk-in')
            ->where('quickActions.1.href', route('reception.index'))
            ->where('quickActions.2.id', 'book-appointment')
            ->where('quickActions.2.href', route('appointments.calendar')));
});

it('omits quick actions without the matching permissions', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('quickActions', []));
});
