<?php

declare(strict_types=1);

use App\Domains\Scheduling\Domain\Model\Slot;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../Support/scheduling.php';
require_once __DIR__.'/../../../../Support/hr.php';

function availabilityFixture(): array
{
    // Always built with full permissions: fixture builders dispatch
    // manage-level commands. Least-privilege users come from limitedTenantUser().
    $fx = schedulingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;
    $resourceId = createDoctorResource($tenantId, $facilityId);
    $typeId = defineAppointmentTypeHelper($tenantId, 'SPEC-CONSULT', 'Specialist Consultation', 30, facilityId: $facilityId);

    return compact('fx', 'tenantId', 'facilityId', 'resourceId', 'typeId');
}

function seedOpenSlot(string $tenantId, string $facilityId, string $resourceId, string $typeId, string $date): string
{
    $slotId = (string) Str::uuid();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $resourceId, $typeId, $slotId, $date): void {
        Slot::create(
            tenantId: $tenantId,
            facilityId: $facilityId,
            resourceId: $resourceId,
            slotDate: $date,
            startTime: CarbonImmutable::parse("{$date} 09:00:00"),
            endTime: CarbonImmutable::parse("{$date} 09:30:00"),
            capacity: 1,
            appointmentTypeId: $typeId,
            slotId: $slotId,
        )->save();
    });

    return $slotId;
}

function bookingPayload(string $resourceId, string $typeId, string $date, array $overrides = []): array
{
    return array_merge([
        'scheduled_date' => $date,
        'scheduled_time' => '09:00',
        'appointment_type_id' => $typeId,
        'resource_id' => $resourceId,
    ], $overrides);
}

it('redirects guests away from the availability endpoint', function (): void {
    $this->get('/workspaces/scheduling/available-slots?resource_id=x&date=2026-10-02')
        ->assertRedirect('/login');
});

it('denies availability without sch.access', function (): void {
    ['tenantId' => $tenantId, 'facilityId' => $facilityId] = availabilityFixture();
    $user = limitedTenantUser($tenantId, $facilityId, ['sch.appointment.book']);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/scheduling/available-slots')
        ->assertForbidden();
});

it('rejects invalid availability parameters', function (): void {
    ['fx' => $fx, 'facilityId' => $facilityId] = availabilityFixture();

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->getJson('/workspaces/scheduling/available-slots?resource_id=not-a-uuid&date=nope')
        ->assertStatus(422);
});

it('returns day slots in the documented shape', function (): void {
    ['fx' => $fx, 'tenantId' => $tenantId, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = availabilityFixture();
    $date = CarbonImmutable::now()->addDay()->format('Y-m-d');
    $slotId = seedOpenSlot($tenantId, $facilityId, $resourceId, $typeId, $date);

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->getJson("/workspaces/scheduling/available-slots?resource_id={$resourceId}&date={$date}&appointment_type_id={$typeId}");

    $response->assertOk()->assertJson([
        'date' => $date,
        'slots' => [[
            'id' => $slotId,
            'resourceId' => $resourceId,
            'capacity' => 1,
            'bookedCount' => 0,
            'status' => 'OPEN',
        ]],
    ]);
});

it('books a valid appointment through HTTP with duration from the type', function (): void {
    ['fx' => $fx, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = availabilityFixture();
    $date = CarbonImmutable::now()->addDay()->format('Y-m-d');
    $patientId = (string) Str::uuid();

    $response = $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post("/patient/{$patientId}/appointments", bookingPayload($resourceId, $typeId, $date));

    $response->assertRedirect();
    $response->assertSessionHas('status');
    $this->assertDatabaseHas('sch_appointments', [
        'patient_id' => $patientId,
        'resource_id' => $resourceId,
    ]);
});

it('rejects an overlapping booking through HTTP with a booking error', function (): void {
    ['fx' => $fx, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = availabilityFixture();
    $date = CarbonImmutable::now()->addDay()->format('Y-m-d');
    $payload = bookingPayload($resourceId, $typeId, $date);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/patient/'.(string) Str::uuid().'/appointments', $payload)
        ->assertRedirect();

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/appointments')
        ->post('/patient/'.(string) Str::uuid().'/appointments', $payload)
        ->assertRedirect('/workspaces/appointments')
        ->assertSessionHasErrors('booking');
});

it('rejects unknown type and resource through HTTP', function (): void {
    ['fx' => $fx, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = availabilityFixture();
    $date = CarbonImmutable::now()->addDay()->format('Y-m-d');

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/appointments')
        ->post('/patient/'.(string) Str::uuid().'/appointments', bookingPayload((string) Str::uuid(), $typeId, $date))
        ->assertSessionHasErrors('booking');

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/appointments')
        ->post('/patient/'.(string) Str::uuid().'/appointments', bookingPayload($resourceId, (string) Str::uuid(), $date))
        ->assertSessionHasErrors('booking');
});

it('denies booking without sch.appointment.book', function (): void {
    ['tenantId' => $tenantId, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = availabilityFixture();
    $user = limitedTenantUser($tenantId, $facilityId, ['sch.access']);
    $date = CarbonImmutable::now()->addDay()->format('Y-m-d');

    $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/patient/'.(string) Str::uuid().'/appointments', bookingPayload($resourceId, $typeId, $date))
        ->assertForbidden();
});

it('books an exact slot through HTTP when slot_id is supplied', function (): void {
    ['fx' => $fx, 'tenantId' => $tenantId, 'facilityId' => $facilityId, 'resourceId' => $resourceId, 'typeId' => $typeId] = availabilityFixture();
    $date = CarbonImmutable::now()->addDay()->format('Y-m-d');
    $slotId = seedOpenSlot($tenantId, $facilityId, $resourceId, $typeId, $date);

    $this->actingAs($fx['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/patient/'.(string) Str::uuid().'/appointments', bookingPayload($resourceId, $typeId, $date, ['slot_id' => $slotId]))
        ->assertRedirect();

    $this->assertDatabaseHas('sch_appointments', ['slot_id' => $slotId]);
});
