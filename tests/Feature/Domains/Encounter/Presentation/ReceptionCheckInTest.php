<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Encounter\Presentation;

use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Organization\Domain\Model\Room;
use App\Domains\Patient\Domain\Model\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\DTO\QueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../../tests/Support/security.php';
require_once __DIR__.'/../../../../../tests/Support/scheduling.php';
require_once __DIR__.'/../../../../../tests/Support/reception.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
});

/**
 * @param  list<string>  $permissions
 * @param  list<string>  $capabilities
 * @return array{tenant: Tenant, facility: mixed, user: User}
 */
function receptionCheckInFixture(
    array $permissions = [
        'encounter.encounters.create',
        'encounter.encounters.read',
        'patient.patients.read',
        'sch.access',
        'queue.ticket.place',
        'sch.appointment.record_arrival',
    ],
    array $capabilities = ['OUTPATIENT_CARE', 'EMERGENCY_CARE'],
): array {
    $fx = makeTenantWithUser($permissions, $capabilities);

    return [
        'tenant' => $fx['tenant'],
        'facility' => $fx['facility'],
        'user' => $fx['user'],
    ];
}

it('successfully checks in a walk-in patient directly to ARRIVED and issues queue ticket', function (): void {
    $fx = receptionCheckInFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create([
            'display_name' => 'Fatuma Ali',
            'status' => 'ACTIVE',
        ])->id);

    $queueRepo = app(QueueRepository::class);
    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'OPD_TRIAGE',
        'name' => 'OPD Triage Desk',
        'ticket_prefix' => 'T',
        'is_active' => true,
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => $queueRepo->save($queue));
    $room = TenantContextScope::run($tenantId, $facilityId, fn (): Room => Room::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'name' => 'Triage Room',
        'code' => 'TRIAGE-1',
        'status' => 'ACTIVE',
    ]));
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $queue, $room): void {
        DB::table('qms_queue_rooms')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'queue_id' => $queue->id,
            'room_id' => $room->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => EncounterType::Outpatient->value,
            'queue_id' => $queue->id,
            'priority' => 'ROUTINE',
            'return_to' => '/workspaces/reception?patient_id='.$patientId.'&date=2026-10-06&status=ARRIVED',
        ]);

    $response->assertRedirect('/workspaces/reception?date=2026-10-06&status=ARRIVED');
    $response->assertSessionHas('status', fn ($msg) => str_contains((string) $msg, 'checked in successfully') && str_contains((string) $msg, 'Queue ticket #'));

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $patientId): void {
        $encounter = DB::table('enc_encounters')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('patient_id', $patientId)
            ->first();

        expect($encounter)->not->toBeNull()
            ->and($encounter->status)->toBe(EncounterStatus::Arrived->value)
            ->and($encounter->encounter_type)->toBe(EncounterType::Outpatient->value)
            ->and($encounter->arrived_at)->not->toBeNull()
            ->and($encounter->created_source)->toBe('RECEPTION');

        $queueEntry = DB::table('qms_queue_entries')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('encounter_id', $encounter->id)
            ->first();

        expect($queueEntry)->not->toBeNull()
            ->and($queueEntry->status)->toBe('WAITING')
            ->and($queueEntry->ticket_number)->toBe('T-001');
    });

});

it('rejects queue check-in when the selected queue has no assigned active room', function (): void {
    $fx = receptionCheckInFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create(['status' => 'ACTIVE'])
        ->id);

    $queue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'UNROUTED',
        'name' => 'Unrouted Queue',
        'ticket_prefix' => 'U',
        'is_active' => true,
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => app(QueueRepository::class)->save($queue));

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => EncounterType::Outpatient->value,
            'queue_id' => $queue->id,
            'return_to' => '/workspaces/reception',
        ])
        ->assertRedirect('/workspaces/reception')
        ->assertSessionHasErrors('queue_id');

    $encounterCount = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): int => DB::table('enc_encounters')->where('patient_id', $patientId)->count(),
    );
    expect($encounterCount)->toBe(0);
});

it('successfully checks in without queue assignment when queue_id is omitted', function (): void {
    $fx = receptionCheckInFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create([
            'display_name' => 'Kassim Said',
            'status' => 'ACTIVE',
        ])->id);

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => EncounterType::Outpatient->value,
            'return_to' => '/workspaces/reception',
        ]);

    $response->assertRedirect('/workspaces/reception');
    $response->assertSessionHas('status', fn ($msg) => str_contains((string) $msg, 'checked in successfully'));

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $patientId): void {
        $encounter = DB::table('enc_encounters')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('patient_id', $patientId)
            ->first();

        expect($encounter)->not->toBeNull()
            ->and($encounter->status)->toBe(EncounterStatus::Arrived->value);

        $queueEntriesCount = DB::table('qms_queue_entries')
            ->where('encounter_id', $encounter->id)
            ->count();

        expect($queueEntriesCount)->toBe(0);
    });
});

it('rejects an unavailable queue before creating an encounter', function (): void {
    $fx = receptionCheckInFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create([
            'display_name' => 'Zuberi Juma',
            'status' => 'ACTIVE',
        ])->id);

    $nonExistentQueueId = (string) Str::uuid7();

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => EncounterType::Outpatient->value,
            'queue_id' => $nonExistentQueueId,
            'return_to' => '/workspaces/reception',
        ]);

    $response->assertRedirect('/workspaces/reception');
    $response->assertSessionHasErrors([
        'queue_id' => 'The selected queue is no longer available. Choose an active queue or check in without queue placement.',
    ]);

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $patientId): void {
        $encounter = DB::table('enc_encounters')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('patient_id', $patientId)
            ->first();

        expect($encounter)->toBeNull();
    });
});

it('reports when a queue fails after encounter creation without claiming check-in fully succeeded', function (): void {
    $fx = receptionCheckInFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create(['status' => 'ACTIVE'])
        ->id);
    $queueId = (string) Str::uuid7();

    $queueingApi = \Mockery::mock(QueueingApi::class);
    $queueingApi->shouldReceive('listFacilityQueues')
        ->once()
        ->with($facilityId, true)
        ->andReturn([
            new QueueDto(
                id: $queueId,
                tenantId: $tenantId,
                facilityId: $facilityId,
                code: 'OPD_TRIAGE',
                name: 'OPD Triage Desk',
                departmentId: null,
                serviceCapability: 'TRIAGE',
                ticketPrefix: 'T',
                isActive: true,
                roomNames: ['Triage Room'],
            ),
        ]);
    $queueingApi->shouldReceive('place')
        ->once()
        ->with(\Mockery::on(static fn ($dto): bool => $dto instanceof PlaceInQueueDto
            && $dto->queueId === $queueId
            && $dto->patientId === $patientId))
        ->andThrow(new \RuntimeException('Temporary queue outage'));
    $this->app->instance(QueueingApi::class, $queueingApi);

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => EncounterType::Outpatient->value,
            'queue_id' => $queueId,
            'return_to' => '/workspaces/reception',
        ]);

    $response->assertRedirect('/workspaces/reception')
        ->assertSessionHasErrors('queue_id')
        ->assertSessionMissing('status');
    expect(session('errors')->first('queue_id'))
        ->toContain('was created, but no queue ticket was issued')
        ->toContain('Do not check the patient in again');

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $facilityId, $patientId): void {
        $encounter = DB::table('enc_encounters')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('patient_id', $patientId)
            ->first();

        expect($encounter)->not->toBeNull()
            ->and(DB::table('qms_queue_entries')->where('encounter_id', $encounter->id)->count())->toBe(0);
    });
});

it('forbids check-in when user lacks encounter.encounters.create permission', function (): void {
    $fx = receptionCheckInFixture(permissions: [
        'encounter.encounters.read',
        'patient.patients.read',
        'sch.access',
    ]);
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create([
            'display_name' => 'Musa Hamisi',
            'status' => 'ACTIVE',
        ])->id);

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => EncounterType::Outpatient->value,
        ]);

    $response->assertForbidden();
});

it('validates required check-in fields', function (): void {
    $fx = receptionCheckInFixture();
    $facilityId = (string) $fx['facility']->id;

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => 'not-a-uuid',
            'encounter_type' => 'INVALID_TYPE',
        ]);

    $response->assertSessionHasErrors(['patient_id', 'encounter_type']);
});

it('returns a form-level check_in error when the patient already has an active encounter', function (): void {
    $fx = receptionCheckInFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create([
            'display_name' => 'Asha Omari',
            'status' => 'ACTIVE',
        ])->id);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => EncounterType::Outpatient->value,
            'return_to' => '/workspaces/reception',
        ])
        ->assertRedirect('/workspaces/reception');

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => EncounterType::Outpatient->value,
        ]);

    $response->assertRedirect('/workspaces/reception')
        ->assertSessionHasErrors(['check_in']);

    $errorMessage = session('errors')->first('check_in');
    expect($errorMessage)->toContain('Asha Omari')
        ->toContain('already has an active visit')
        ->not->toContain('Invariant 12b');
});

it('creates a new follow-up encounter for a return appointment after the previous visit is completed', function (): void {
    $bookFx = schedulingFixture();
    $tenantId = (string) $bookFx['tenant']->id;
    $facilityId = (string) $bookFx['facility']->id;

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): void {
        DB::table('org_facility_capabilities')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'capability_code' => 'OUTPATIENT_CARE',
            'state' => 'ENABLED',
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create([
            'display_name' => 'Fatma Rashid',
            'status' => 'ACTIVE',
        ])->id);
    $previousEncounterId = TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) Encounter::factory()
        ->forTenant($tenantId)
        ->atFacility($facilityId)
        ->forPatient($patientId)
        ->completed()
        ->create()
        ->id);

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'REC-FOLLOWUP', 'Follow-up review', 30, facilityId: $facilityId);
    $start = CarbonImmutable::now()->setTime(11, 0);
    $appointmentId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: $patientId,
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $start->toIso8601String(),
        scheduledEnd: $start->addMinutes(30)->toIso8601String(),
    );

    $receptionUser = createReceptionUser($tenantId, $facilityId, [
        'encounter.encounters.create',
        'encounter.encounters.read',
        'patient.patients.read',
        'sch.access',
        'sch.appointment.record_arrival',
    ]);

    $this->actingAs($receptionUser)
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => EncounterType::FollowUp->value,
            'appointment_id' => $appointmentId,
            'return_to' => '/workspaces/reception',
        ])
        ->assertRedirect('/workspaces/reception')
        ->assertSessionHas('status');

    TenantContextScope::run($tenantId, $facilityId, function () use ($appointmentId, $patientId, $facilityId, $previousEncounterId): void {
        $appointment = DB::table('sch_appointments')->where('id', $appointmentId)->first();
        expect($appointment)->not->toBeNull()
            ->and($appointment->status)->toBe('ARRIVED');

        $encounters = DB::table('enc_encounters')
            ->where('facility_id', $facilityId)
            ->where('patient_id', $patientId)
            ->orderBy('created_at')
            ->get();
        $encounter = $encounters->firstWhere('id', '!=', $previousEncounterId);
        expect($encounters)->toHaveCount(2)
            ->and($encounters->firstWhere('id', $previousEncounterId)->status)->toBe(EncounterStatus::Completed->value)
            ->and($encounter)->not->toBeNull()
            ->and($encounter->encounter_type)->toBe(EncounterType::FollowUp->value)
            ->and($encounter->status)->toBe(EncounterStatus::Arrived->value)
            ->and($encounter->created_source)->toBe('RECEPTION');
    });
});

it('rejects appointment check-in when the appointment belongs to a different patient', function (): void {
    $bookFx = schedulingFixture();
    $tenantId = (string) $bookFx['tenant']->id;
    $facilityId = (string) $bookFx['facility']->id;

    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create(['display_name' => 'Fatma Rashid', 'status' => 'ACTIVE'])->id);
    $otherPatientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create(['display_name' => 'Other Patient', 'status' => 'ACTIVE'])->id);

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'REC-MIS', 'Reception Mismatch', 30, facilityId: $facilityId);
    $start = CarbonImmutable::now()->setTime(11, 0);
    $appointmentId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: $patientId,
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $start->toIso8601String(),
        scheduledEnd: $start->addMinutes(30)->toIso8601String(),
    );

    $receptionUser = createReceptionUser($tenantId, $facilityId, [
        'encounter.encounters.create',
        'encounter.encounters.read',
        'patient.patients.read',
        'sch.access',
        'sch.appointment.record_arrival',
    ]);

    $this->actingAs($receptionUser)
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $otherPatientId,
            'encounter_type' => EncounterType::Outpatient->value,
            'appointment_id' => $appointmentId,
        ])
        ->assertRedirect('/workspaces/reception')
        ->assertSessionHasErrors(['check_in']);
});

it('redirects to relative return_to url when provided upon successful check-in', function (): void {
    $fx = receptionCheckInFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $patientId = TenantContextScope::run($tenantId, null, fn () => (string) Patient::factory()
        ->forTenant($tenantId)
        ->create(['display_name' => 'Neema Bakari', 'status' => 'ACTIVE'])->id);

    $returnUrl = '/workspaces/patient/'.$patientId;

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => EncounterType::Outpatient->value,
            'return_to' => $returnUrl,
        ]);

    $response->assertRedirect($returnUrl);
    expect(session('status'))->toContain('Patient checked in successfully');
});
