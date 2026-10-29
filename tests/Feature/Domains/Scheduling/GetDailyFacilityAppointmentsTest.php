<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Scheduling;

use App\Domains\Scheduling\Application\Query\GetDailyFacilityAppointments;
use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Domains\Scheduling\Domain\ValueObject\AppointmentStatus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/scheduling.php';

beforeEach(function (): void {
    $this->targetDate = CarbonImmutable::parse('2026-09-15');
});

it('returns the daily facility appointments for the requested date', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'GEN-OPD', 'General OPD', 30, facilityId: $facilityId);

    $id1 = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->setTime(9, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(9, 30)->toIso8601String(),
        reason: 'Morning checkup',
    );

    $id2 = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->setTime(14, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(14, 30)->toIso8601String(),
        reason: 'Afternoon checkup',
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $id1, $id2): void {
        /** @var list<AppointmentSummary> $results */
        $results = app(QueryBus::class)->dispatch(new GetDailyFacilityAppointments(
            facilityId: $facilityId,
            date: $this->targetDate,
        ));

        expect($results)->toHaveCount(2);

        $ids = array_map(fn (AppointmentSummary $a) => $a->id, $results);
        expect($ids)->toContain($id1, $id2)
            ->and($results[0]->scheduledStart)->toBeLessThan($results[1]->scheduledStart);
    });
});

it('strictly excludes appointments from adjacent days (date boundary proof)', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'CARDIO', 'Cardiology', 30, facilityId: $facilityId);

    // Yesterday
    bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->subDay()->setTime(10, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->subDay()->setTime(10, 30)->toIso8601String(),
    );

    // Target day
    $todayId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->setTime(10, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(10, 30)->toIso8601String(),
    );

    // Tomorrow
    bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->addDay()->setTime(10, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->addDay()->setTime(10, 30)->toIso8601String(),
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $todayId): void {
        /** @var list<AppointmentSummary> $results */
        $results = app(QueryBus::class)->dispatch(new GetDailyFacilityAppointments(
            facilityId: $facilityId,
            date: $this->targetDate,
        ));

        expect($results)->toHaveCount(1)
            ->and($results[0]->id)->toBe($todayId);
    });
});

it('returns the complete facility schedule regardless of appointment status (complete-schedule semantics)', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'PED-CHECK', 'Pediatrics', 30, facilityId: $facilityId);

    // Create 3 appointments on target date
    $id1 = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->setTime(8, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(8, 30)->toIso8601String(),
    );

    $id2 = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->setTime(9, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(9, 30)->toIso8601String(),
    );

    $id3 = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->setTime(10, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(10, 30)->toIso8601String(),
    );

    // Mutate statuses: id1 stays BOOKED, id2 -> CANCELLED, id3 -> ARRIVED
    TenantContextScope::run($tenantId, $facilityId, function () use ($id2, $id3): void {
        $a2 = Appointment::query()->find($id2);
        $a2->status = AppointmentStatus::CANCELLED;
        $a2->cancellation_reason = 'Patient requested cancellation';
        $a2->save();

        $a3 = Appointment::query()->find($id3);
        $a3->status = AppointmentStatus::ARRIVED;
        $a3->arrived_at = CarbonImmutable::now();
        $a3->save();
    });

    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $id1, $id2, $id3): void {
        /** @var list<AppointmentSummary> $results */
        $results = app(QueryBus::class)->dispatch(new GetDailyFacilityAppointments(
            facilityId: $facilityId,
            date: $this->targetDate,
        ));

        // Complete daily schedule is returned without status filtering
        expect($results)->toHaveCount(3);

        $statusMap = [];
        foreach ($results as $item) {
            $statusMap[$item->id] = $item->status;
        }

        expect($statusMap[$id1])->toBe('BOOKED')
            ->and($statusMap[$id2])->toBe('CANCELLED')
            ->and($statusMap[$id3])->toBe('ARRIVED');
    });
});

it('executes a single bulk SQL query against sch_appointments without N+1', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'DENTAL', 'Dental Checkup', 20, facilityId: $facilityId);

    for ($i = 0; $i < 5; $i++) {
        bookAppointmentHelper(
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: (string) Str::uuid(),
            resourceId: $resourceId,
            appointmentTypeId: $typeId,
            scheduledStart: $this->targetDate->setTime(9, $i * 20)->toIso8601String(),
            scheduledEnd: $this->targetDate->setTime(9, ($i + 1) * 20)->toIso8601String(),
        );
    }

    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId): void {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $results = app(QueryBus::class)->dispatch(new GetDailyFacilityAppointments(
            facilityId: $facilityId,
            date: $this->targetDate,
        ));

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($results)->toHaveCount(5);

        // Filter queries to sch_appointments table
        $appointmentQueries = array_filter(
            $queries,
            fn (array $q): bool => str_contains((string) $q['query'], 'sch_appointments')
        );

        // Exactly ONE query on sch_appointments — no N+1
        expect($appointmentQueries)->toHaveCount(1);
    });
});

it('returns an empty array when authorized and zero appointments match (not 404)', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId): void {
        /** @var list<AppointmentSummary> $results */
        $results = app(QueryBus::class)->dispatch(new GetDailyFacilityAppointments(
            facilityId: $facilityId,
            date: $this->targetDate,
        ));

        expect($results)->toBeArray()->toBeEmpty();
    });
});

it('enforces facility authorization and denies callers without permission with 403', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    // Create a second facility that the user does NOT have permission for
    $otherFacilityId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, $facilityId, function () use ($otherFacilityId): void {
        expect(fn () => app(QueryBus::class)->dispatch(new GetDailyFacilityAppointments(
            facilityId: $otherFacilityId,
            date: $this->targetDate,
        )))->toThrow(AuthorizationException::class);
    });
});

it('preserves facility isolation and ignores appointments at other facilities', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityA = (string) $fx['facility']->id;

    $facilityB = (string) DB::table('org_facilities')->insertGetId([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'organization_id' => (string) $fx['organization']->id,
        'name' => 'Facility B',
        'code' => 'FAC-B',
        'facility_type' => 'HOSPITAL',
        'care_level' => 'PRIMARY',
        'ownership_type' => 'PUBLIC',
        'status' => 'ACTIVE',
        'created_at' => now(),
        'updated_at' => now(),
    ], 'id');

    $resourceA = createDoctorResource($tenantId, $facilityA);
    $typeA = defineAppointmentTypeHelper($tenantId, 'FAC-A-TYPE', 'Type A', 30, facilityId: $facilityA);

    $idA = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityA,
        patientId: (string) Str::uuid(),
        resourceId: $resourceA,
        appointmentTypeId: $typeA,
        scheduledStart: $this->targetDate->setTime(11, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(11, 30)->toIso8601String(),
    );

    // Direct insert into Facility B
    $idB = (string) Str::uuid7();
    DB::table('sch_appointments')->insert([
        'id' => $idB,
        'tenant_id' => $tenantId,
        'facility_id' => $facilityB,
        'appointment_number' => 'APT-FACB-001',
        'patient_id' => (string) Str::uuid(),
        'resource_id' => $resourceA,
        'appointment_type_id' => $typeA,
        'scheduled_start' => $this->targetDate->setTime(11, 0)->toIso8601String(),
        'scheduled_end' => $this->targetDate->setTime(11, 30)->toIso8601String(),
        'status' => 'BOOKED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    TenantContextScope::run($tenantId, $facilityA, function () use ($facilityA, $idA, $idB): void {
        /** @var list<AppointmentSummary> $results */
        $results = app(QueryBus::class)->dispatch(new GetDailyFacilityAppointments(
            facilityId: $facilityA,
            date: $this->targetDate,
        ));

        expect($results)->toHaveCount(1)
            ->and($results[0]->id)->toBe($idA)
            ->and($results[0]->id)->not->toBe($idB);
    });
});

it('preserves tenant RLS isolation and ignores appointments of other tenants', function (): void {
    $fxA = schedulingFixture();
    $tenantA = (string) $fxA['tenant']->id;
    $facilityA = (string) $fxA['facility']->id;

    $resourceA = createDoctorResource($tenantA, $facilityA);
    $typeA = defineAppointmentTypeHelper($tenantA, 'TEN-A-TYPE', 'Type A', 30, facilityId: $facilityA);

    $idA = bookAppointmentHelper(
        tenantId: $tenantA,
        facilityId: $facilityA,
        patientId: (string) Str::uuid(),
        resourceId: $resourceA,
        appointmentTypeId: $typeA,
        scheduledStart: $this->targetDate->setTime(12, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(12, 30)->toIso8601String(),
    );

    // Second tenant
    $fxB = makeTenantWithUser(['sch.access']);
    $tenantB = (string) $fxB['tenant']->id;
    $facilityB = (string) $fxB['facility']->id;

    // Run query as Tenant B for Facility A — should fail closed or return empty under RLS
    test()->actingAs($fxB['user']);

    TenantContextScope::run($tenantB, $facilityB, function () use ($facilityA): void {
        expect(fn () => app(QueryBus::class)->dispatch(new GetDailyFacilityAppointments(
            facilityId: $facilityA,
            date: $this->targetDate,
        )))->toThrow(AuthorizationException::class);
    });
});

it('preserves canonical AppointmentSummary DTO fields verbatim', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'PREC-TYPE', 'Precision Test', 30, facilityId: $facilityId);
    $patientId = (string) Str::uuid();

    $start = $this->targetDate->setTime(15, 0);
    $end = $this->targetDate->setTime(15, 30);

    $aptId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: $patientId,
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $start->toIso8601String(),
        scheduledEnd: $end->toIso8601String(),
        reason: 'Precise field verification',
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $aptId, $patientId, $resourceId, $typeId, $start, $end): void {
        /** @var list<AppointmentSummary> $results */
        $results = app(QueryBus::class)->dispatch(new GetDailyFacilityAppointments(
            facilityId: $facilityId,
            date: $this->targetDate,
        ));

        expect($results)->toHaveCount(1);
        $dto = $results[0];

        expect($dto)->toBeInstanceOf(AppointmentSummary::class)
            ->and($dto->id)->toBe($aptId)
            ->and($dto->facilityId)->toBe($facilityId)
            ->and($dto->appointmentNumber)->toStartWith('APT-')
            ->and($dto->slotId)->toBeNull()
            ->and($dto->patientId)->toBe($patientId)
            ->and($dto->resourceId)->toBe($resourceId)
            ->and($dto->appointmentTypeId)->toBe($typeId)
            ->and($dto->scheduledStart)->toBe($start->toIso8601String())
            ->and($dto->scheduledEnd)->toBe($end->toIso8601String())
            ->and($dto->status)->toBe('BOOKED')
            ->and($dto->reason)->toBe('Precise field verification');
    });
});

it('is accessible via SchedulingApi::getDailyFacilityAppointments', function (): void {
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'API-TYPE', 'API Test', 30, facilityId: $facilityId);

    $aptId = bookAppointmentHelper(
        tenantId: $tenantId,
        facilityId: $facilityId,
        patientId: (string) Str::uuid(),
        resourceId: $resourceId,
        appointmentTypeId: $typeId,
        scheduledStart: $this->targetDate->setTime(16, 0)->toIso8601String(),
        scheduledEnd: $this->targetDate->setTime(16, 30)->toIso8601String(),
        reason: 'Public contract check',
    );

    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $aptId): void {
        /** @var SchedulingApi $api */
        $api = app(SchedulingApi::class);

        $results = $api->getDailyFacilityAppointments($facilityId, $this->targetDate);

        expect($results)->toBeArray()->toHaveCount(1)
            ->and($results[0])->toBeInstanceOf(AppointmentSummary::class)
            ->and($results[0]->id)->toBe($aptId)
            ->and($results[0]->reason)->toBe('Public contract check');
    });
});
