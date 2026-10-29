<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Scheduling\Presentation;

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Scheduling\Application\Command\BookAppointment;
use App\Domains\Scheduling\Domain\Model\Slot;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function appointmentActionFixture(array $permissions = ['sch.access', 'sch.appointment.book', 'sch.appointment.manage', 'sch.appointment.record_arrival', 'sch.resource.manage', 'sch.appointment_type.manage', 'hr.access', 'hr.person.manage', 'hr.staff.read', 'hr.staff.manage']): array
{
    return queueingFixture($permissions);
}

function bookTestAppointment(string $tenantId, string $facilityId, string $status = 'BOOKED'): string
{
    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'GEN-CONSULT', 'General Consultation', 30, facilityId: $facilityId);
    $start = CarbonImmutable::now()->addDay()->setTime(9, 0);

    return TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $resourceId, $typeId, $start, $status): string {
        $id = (string) Str::uuid7();
        app(CommandBus::class)->dispatch(new BookAppointment(
            patientId: (string) Str::uuid7(),
            resourceId: $resourceId,
            appointmentTypeId: $typeId,
            scheduledStart: $start->toIso8601String(),
            scheduledEnd: $start->addMinutes(30)->toIso8601String(),
            facilityId: $facilityId,
            appointmentId: $id,
        ));

        if ($status !== 'BOOKED') {
            DB::table('sch_appointments')->where('id', $id)->update(['status' => $status]);
        }

        return $id;
    });
}

function appointmentSecondFacility(string $tenantId, string $orgId, string $userId, string $roleId): Facility
{
    return TenantContextScope::run($tenantId, null, function () use ($tenantId, $orgId, $userId, $roleId): Facility {
        $facility = Facility::factory()->forTenant($tenantId, $orgId)->create();

        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role_id' => $roleId,
            'facility_id' => $facility->id,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $facility;
    });
}

// Slice pages unbuilt: disable missing-page enforcement so response shapes
// stay testable until the .tsx lands (then delete this block).
beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
});

test('recording arrival moves a booked appointment to arrived', function (): void {
    $fixture = appointmentActionFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $id = bookTestAppointment($tenantId, $facilityId);

    $this->actingAs($fixture['user'])
        ->post(route('appointments.arrive', ['appointment' => $id]))
        ->assertRedirect(route('reception.index'));

    $status = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => DB::table('sch_appointments')->where('id', $id)->value('status'),
    );

    expect($status)->toBe('ARRIVED');
});

test('arrival without the record permission is forbidden', function (): void {
    $fixture = appointmentActionFixture(['sch.access', 'sch.appointment.book', 'sch.resource.manage', 'sch.appointment_type.manage', 'hr.access', 'hr.person.manage', 'hr.staff.read', 'hr.staff.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $id = bookTestAppointment($tenantId, $facilityId);

    $this->actingAs($fixture['user'])
        ->post(route('appointments.arrive', ['appointment' => $id]))
        ->assertForbidden();
});

test('cancelling requires the manage permission', function (): void {
    $fixture = appointmentActionFixture(['sch.access', 'sch.appointment.book', 'sch.appointment.record_arrival', 'sch.resource.manage', 'sch.appointment_type.manage', 'hr.access', 'hr.person.manage', 'hr.staff.read', 'hr.staff.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $id = bookTestAppointment($tenantId, $facilityId);

    $this->actingAs($fixture['user'])
        ->post(route('appointments.cancel', ['appointment' => $id]), ['reason' => 'Patient called in sick'])
        ->assertForbidden();
});

test('cancelling with reason closes the appointment', function (): void {
    $fixture = appointmentActionFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $id = bookTestAppointment($tenantId, $facilityId);

    $this->actingAs($fixture['user'])
        ->post(route('appointments.cancel', ['appointment' => $id]), ['reason' => 'Patient called in sick'])
        ->assertRedirect(route('reception.index'));

    $status = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => DB::table('sch_appointments')->where('id', $id)->value('status'),
    );

    expect($status)->toBe('CANCELLED');
});

test('rescheduling moves the appointment to the new slot', function (): void {
    $fixture = appointmentActionFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $id = bookTestAppointment($tenantId, $facilityId);
    $start = CarbonImmutable::now()->addDays(2)->setTime(10, 0);

    $this->actingAs($fixture['user'])->post(route('appointments.reschedule', ['appointment' => $id]), [
        'new_start' => $start->format('Y-m-d H:i:s'),
        'new_end' => $start->addMinutes(30)->format('Y-m-d H:i:s'),
    ])->assertRedirect(route('reception.index'));

    $stored = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => (string) DB::table('sch_appointments')->where('id', $id)->value('scheduled_start'),
    );

    expect($stored)->toContain($start->format('Y-m-d'));
});

test('rescheduling to an inverted range fails validation', function (): void {
    $fixture = appointmentActionFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $id = bookTestAppointment($tenantId, $facilityId);

    $this->actingAs($fixture['user'])->post(route('appointments.reschedule', ['appointment' => $id]), [
        'new_start' => CarbonImmutable::now()->addDays(2)->setTime(11, 0)->format('Y-m-d H:i:s'),
        'new_end' => CarbonImmutable::now()->addDays(2)->setTime(10, 0)->format('Y-m-d H:i:s'),
    ])->assertSessionHasErrors('new_end');
});

test('arrival on a completed appointment reports a domain error', function (): void {
    $fixture = appointmentActionFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $id = bookTestAppointment($tenantId, $facilityId, 'COMPLETED');

    $this->actingAs($fixture['user'])
        ->post(route('appointments.arrive', ['appointment' => $id]))
        ->assertSessionHasErrors('arrival');
});

test('an appointment from another facility is not found', function (): void {
    $fixture = appointmentActionFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityAId = (string) $fixture['facility']->id;
    $facilityB = appointmentSecondFacility($tenantId, (string) $fixture['organization']->id, (string) $fixture['user']->id, (string) $fixture['role_id']);
    $id = bookTestAppointment($tenantId, (string) $facilityB->id);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityAId])
        ->post(route('appointments.arrive', ['appointment' => $id]))
        ->assertNotFound();
});

test('marking a no-show closes the appointment', function (): void {
    $fixture = appointmentActionFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $id = bookTestAppointment($tenantId, $facilityId);

    $this->actingAs($fixture['user'])
        ->post(route('appointments.no-show', ['appointment' => $id]))
        ->assertRedirect(route('reception.index'));

    $status = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => DB::table('sch_appointments')->where('id', $id)->value('status'),
    );

    expect($status)->toBe('NO_SHOW');
});

test('confirming moves a booked appointment to confirmed', function (): void {
    $fixture = appointmentActionFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $id = bookTestAppointment($tenantId, $facilityId);

    $this->actingAs($fixture['user'])
        ->post(route('appointments.confirm', ['appointment' => $id]))
        ->assertRedirect(route('reception.index'));

    $status = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => DB::table('sch_appointments')->where('id', $id)->value('status'),
    );

    expect($status)->toBe('CONFIRMED');
});

test('rescheduling onto an occupied interval fails with a reschedule error', function (): void {
    $fixture = appointmentActionFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $first = bookTestAppointment($tenantId, $facilityId);

    $row = TenantContextScope::run($tenantId, $facilityId, fn (): object => DB::table('sch_appointments')->where('id', $first)->first(['resource_id', 'appointment_type_id']));
    $second = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, function () use ($facilityId, $row, $second): void {
        app(CommandBus::class)->dispatch(new BookAppointment(
            patientId: (string) Str::uuid7(),
            resourceId: (string) $row->resource_id,
            appointmentTypeId: (string) $row->appointment_type_id,
            scheduledStart: CarbonImmutable::now()->addDay()->setTime(11, 0)->toIso8601String(),
            scheduledEnd: CarbonImmutable::now()->addDay()->setTime(11, 30)->toIso8601String(),
            facilityId: $facilityId,
            appointmentId: $second,
        ));
    });

    $this->actingAs($fixture['user'])
        ->from(route('reception.index'))
        ->post(route('appointments.reschedule', ['appointment' => $second]), [
            'new_start' => CarbonImmutable::now()->addDay()->setTime(9, 15)->format('Y-m-d H:i:s'),
            'new_end' => CarbonImmutable::now()->addDay()->setTime(9, 45)->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect(route('reception.index'))
        ->assertSessionHasErrors('reschedule');
});

test('rescheduling without manage permission is forbidden', function (): void {
    $fixture = appointmentActionFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $id = bookTestAppointment($tenantId, $facilityId);
    $limited = limitedTenantUser($tenantId, $facilityId, ['sch.access', 'sch.appointment.book']);
    $start = CarbonImmutable::now()->addDays(2)->setTime(10, 0);

    $this->actingAs($limited)->post(route('appointments.reschedule', ['appointment' => $id]), [
        'new_start' => $start->format('Y-m-d H:i:s'),
        'new_end' => $start->addMinutes(30)->format('Y-m-d H:i:s'),
    ])->assertForbidden();
});

test('rescheduling with an invalid slot id fails validation', function (): void {
    $fixture = appointmentActionFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $id = bookTestAppointment($tenantId, $facilityId);
    $start = CarbonImmutable::now()->addDays(2)->setTime(10, 0);

    $this->actingAs($fixture['user'])
        ->from(route('reception.index'))
        ->post(route('appointments.reschedule', ['appointment' => $id]), [
            'new_start' => $start->format('Y-m-d H:i:s'),
            'new_end' => $start->addMinutes(30)->format('Y-m-d H:i:s'),
            'slot_id' => 'not-a-uuid',
        ])
        ->assertSessionHasErrors('slot_id');
});

test('rescheduling onto a generated slot claims it', function (): void {
    $fixture = appointmentActionFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $id = bookTestAppointment($tenantId, $facilityId);
    $day = CarbonImmutable::now()->addDays(3)->format('Y-m-d');

    // A slot for a DIFFERENT resource: mismatch must be rejected, proving
    // slot validation still applies on reschedule.
    $slotId = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $day): string {
        $otherResource = createDoctorResource($tenantId, $facilityId, 'Gregory', 'House');
        $otherType = defineAppointmentTypeHelper($tenantId, 'OTHER-CONSULT', 'Other Consultation', 30, facilityId: $facilityId);
        $slotId = (string) Str::uuid7();
        Slot::create(
            tenantId: $tenantId,
            facilityId: $facilityId,
            resourceId: $otherResource,
            slotDate: $day,
            startTime: CarbonImmutable::parse("{$day} 09:00:00"),
            endTime: CarbonImmutable::parse("{$day} 09:30:00"),
            capacity: 1,
            appointmentTypeId: $otherType,
            slotId: $slotId,
        )->save();

        return $slotId;
    });

    $this->actingAs($fixture['user'])
        ->from(route('reception.index'))
        ->post(route('appointments.reschedule', ['appointment' => $id]), [
            'new_start' => "{$day} 09:00:00",
            'new_end' => "{$day} 09:30:00",
            'slot_id' => $slotId,
        ])
        ->assertSessionHasErrors('reschedule');
});
