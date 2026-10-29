<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Scheduling\Presentation;

use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Scheduling\Contracts\Dto\AppointmentSummary;
use App\Domains\Scheduling\Contracts\SchedulingApi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Mockery;

uses(RefreshDatabase::class);

test('the standalone daily board redirects into the merged front-desk board', function (): void {
    $fixture = queueingFixture(['sch.access', 'patient.patients.read', 'encounter.encounters.read']);

    $this->actingAs($fixture['user'])
        ->get(route('appointments.index'))
        ->assertRedirect(route('reception.index'));
});

test('the redirect preserves the requested date', function (): void {
    $fixture = queueingFixture(['sch.access', 'patient.patients.read', 'encounter.encounters.read']);

    $this->actingAs($fixture['user'])
        ->get(route('appointments.index', ['date' => '2026-10-02']))
        ->assertRedirect(route('reception.index', ['date' => '2026-10-02']));
});

test('guests are redirected to login from the old board URL', function (): void {
    $this->get(route('appointments.index'))->assertRedirect(route('login'));
});

test('the calendar page requires scheduling access', function (): void {
    $fixture = queueingFixture(['queue.access']);

    $this->actingAs($fixture['user'])
        ->get(route('appointments.calendar'))
        ->assertForbidden();
});

test('the calendar page renders the month grid', function (): void {
    $fixture = queueingFixture(['sch.access']);

    $this->actingAs($fixture['user'])
        ->get(route('appointments.calendar', ['month' => '2026-09']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Appointments/Calendar')
            ->where('month', '2026-09')
            ->where('view', 'month')
            ->has('weekIndex')
            ->has('gridStart')
            ->has('days'));
});

test('the calendar preserves view and type filter state', function (): void {
    $fixture = queueingFixture(['sch.access']);

    $this->actingAs($fixture['user'])
        ->get(route('appointments.calendar', [
            'month' => '2026-10',
            'view' => 'week',
            'week' => '2',
            'type' => 'General Consultation',
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('view', 'week')
            ->where('weekIndex', 2)
            ->where('appointmentTypeFilter', 'General Consultation'));
});

test('switching to day view focuses a date without opening the day agenda', function (): void {
    $fixture = queueingFixture(['sch.access']);

    $this->actingAs($fixture['user'])
        ->get(route('appointments.calendar', ['view' => 'day', 'date' => '2026-10-05']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('view', 'day')
            ->where('focusDate', '2026-10-05')
            ->where('selectedDay', null)
            ->has('appointments', 0));
});

test('the selected calendar day includes appointment and patient context', function (): void {
    $fixture = queueingFixture(['sch.access', 'patient.patients.read']);
    $patientId = (string) str()->uuid();
    $appointment = new AppointmentSummary(
        id: (string) str()->uuid(),
        facilityId: (string) $fixture['facility']->id,
        appointmentNumber: 'APT-0001',
        slotId: null,
        patientId: $patientId,
        resourceId: 'resource-1',
        appointmentTypeId: 'type-1',
        scheduledStart: '2026-10-03T09:00:00+03:00',
        scheduledEnd: '2026-10-03T09:30:00+03:00',
        status: 'CONFIRMED',
        reason: 'Follow-up',
    );

    $schedulingApi = Mockery::mock(SchedulingApi::class);
    $schedulingApi->shouldReceive('getDailyFacilityAppointments')
        ->once()
        ->withArgs(fn (string $facilityId, CarbonImmutable $date): bool =>
            $facilityId === (string) $fixture['facility']->id && $date->toDateString() === '2026-10-03')
        ->andReturn([$appointment]);
    $schedulingApi->shouldReceive('getSchedulingDisplayNames')
        ->once()
        ->andReturn([
            'types' => ['type-1' => 'Consultation'],
            'resources' => ['resource-1' => 'Dr. Amina'],
        ]);
    $this->app->instance(SchedulingApi::class, $schedulingApi);

    $patientApi = Mockery::mock(PatientApi::class);
    $patientApi->shouldReceive('getPatientSummaries')
        ->once()
        ->with([$patientId])
        ->andReturn([
            $patientId => new PatientSummary(
                patientId: $patientId,
                patientNumber: 'PAT-0001',
                displayName: 'Jane Doe',
                dateOfBirth: null,
                sex: 'F',
                status: 'ACTIVE',
                isProvisional: false,
            ),
        ]);
    $this->app->instance(PatientApi::class, $patientApi);

    $this->actingAs($fixture['user'])
        ->get(route('appointments.calendar', ['month' => '2026-10', 'day' => '2026-10-03']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Appointments/Calendar')
            ->where('month', '2026-10')
            ->where('view', 'month')
            ->where('weekIndex', 0)
            ->where('selectedDay', '2026-10-03')
            ->has('appointments', 1)
            ->where('appointments.0.id', $appointment->id)
            ->where('appointments.0.appointmentNumber', 'APT-0001')
            ->where('appointments.0.patient.displayName', 'Jane Doe')
            ->where('appointments.0.appointmentTypeName', 'Consultation')
            ->where('appointments.0.resourceName', 'Dr. Amina')
            ->where('appointmentsError', false));
});

test('the selected calendar day omits patient identity without patient read permission', function (): void {
    $fixture = queueingFixture(['sch.access']);
    $appointment = new AppointmentSummary(
        id: (string) str()->uuid(),
        facilityId: (string) $fixture['facility']->id,
        appointmentNumber: 'APT-0002',
        slotId: null,
        patientId: (string) str()->uuid(),
        resourceId: 'resource-2',
        appointmentTypeId: 'type-2',
        scheduledStart: '2026-10-03T10:00:00+03:00',
        scheduledEnd: '2026-10-03T10:30:00+03:00',
        status: 'BOOKED',
        reason: null,
    );

    $schedulingApi = Mockery::mock(SchedulingApi::class);
    $schedulingApi->shouldReceive('getDailyFacilityAppointments')->once()->andReturn([$appointment]);
    $schedulingApi->shouldReceive('getSchedulingDisplayNames')->once()->andReturn([
        'types' => [],
        'resources' => [],
    ]);
    $this->app->instance(SchedulingApi::class, $schedulingApi);

    $this->actingAs($fixture['user'])
        ->get(route('appointments.calendar', ['day' => '2026-10-03']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('appointments.0.appointmentNumber', 'APT-0002')
            ->where('appointments.0.patient', null));
});

test('an invalid month falls back to the current month', function (): void {
    $fixture = queueingFixture(['sch.access']);

    $this->actingAs($fixture['user'])
        ->get(route('appointments.calendar', ['month' => '2026-13']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('month', CarbonImmutable::now()->format('Y-m')));
});

test('the calendar data endpoint returns day counts', function (): void {
    $fixture = queueingFixture(['sch.access']);

    $this->actingAs($fixture['user'])
        ->getJson(route('appointments.api.calendar', ['start' => '2026-09-01', 'end' => '2026-09-30']))
        ->assertOk()
        ->assertJsonStructure(['days']);
});

test('the calendar data endpoint rejects oversized ranges', function (): void {
    $fixture = queueingFixture(['sch.access']);

    $this->actingAs($fixture['user'])
        ->getJson(route('appointments.api.calendar', ['start' => '2026-01-01', 'end' => '2026-12-31']))
        ->assertOk()
        ->assertExactJson(['days' => []]);
});

test('the booking options endpoint requires scheduling access', function (): void {
    $fixture = queueingFixture(['queue.access']);

    $this->actingAs($fixture['user'])
        ->get(route('scheduling.appointment-options'))
        ->assertForbidden();
});

test('the booking options endpoint returns types and resources', function (): void {
    $fixture = queueingFixture(['sch.access']);

    $this->actingAs($fixture['user'])
        ->get(route('scheduling.appointment-options'))
        ->assertOk()
        ->assertJsonStructure(['types', 'resources']);
});
