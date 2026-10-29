<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Room;
use App\Platform\Queueing\Contracts\DTO\CallTicketDto;
use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function nurseStationFixture(): array
{
    $permissions = [
        'clinical.access',
        'clinical.vitals.read',
        'clinical.vitals.record',
        'clinical.triage.read',
        'clinical.triage.record',
        'patient.patients.read',
        'patient.patients.create',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
        'queue.ticket.view',
        'queue.ticket.place',
        'queue.ticket.call',
        'queue.ticket.service',
        'queue.ticket.transfer',
    ];
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture($permissions);
    [$patientId, $encounterId] = clinicalPatientWithEncounter(
        (string) $tenant->id,
        (string) $facility->id,
        (string) $user->id,
    );
    $queue = new Queue([
        'tenant_id' => (string) $tenant->id,
        'facility_id' => (string) $facility->id,
        'code' => 'TRIAGE',
        'name' => 'OPD Triage',
        'service_capability' => ServiceCapability::TRIAGE->value,
        'ticket_prefix' => 'T',
    ]);
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueueRepository::class)->save($queue));
    $ticket = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueueingApi::class)->place(
        new PlaceInQueueDto(
            encounterId: $encounterId,
            patientId: $patientId,
            queueId: $queue->id,
            priority: 'ROUTINE',
        ),
    ));

    return compact('tenant', 'facility', 'user', 'patientId', 'encounterId', 'queue', 'ticket');
}

function nurseStartTriageService(array $fixture): void
{
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $room = TenantContextScope::run($tenantId, $facilityId, fn (): Room => Room::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'name' => 'Triage Room',
        'code' => 'TRIAGE-1',
        'status' => 'ACTIVE',
    ]));
    queueAssignRoom($tenantId, $facilityId, (string) $fixture['queue']->id, (string) $room->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $fixture['queue']->id, (string) $room->id, (string) $fixture['user']->id);
    $called = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => app(QueueingApi::class)->call(new CallTicketDto($fixture['ticket']->id, $room->id)),
    );
    expect($called->status)->toBe('CALLED');
    test()->post(route('nurse-station.entries.start', $fixture['ticket']->id), [
        'return_to' => '/workspaces/nurse-station/entries/'.$fixture['ticket']->id,
    ])->assertRedirect('/workspaces/nurse-station/entries/'.$fixture['ticket']->id)->assertSessionHasNoErrors();
}

test('nurse station lists active triage queue entries and opens the encounter context', function () {
    $fixture = nurseStationFixture();
    $ticket = $fixture['ticket'];

    $this->get(route('nurse-station.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('NurseStation/Index')
            ->has('entries', 1)
            ->where('entries.0.id', $ticket->id)
            ->where('entries.0.ticketNumber', $ticket->ticketNumber));

    $this->get(route('nurse-station.entries.show', $ticket->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('NurseStation/Show')
            ->where('patient.patientId', $fixture['patientId'])
            ->where('encounter.encounterId', $fixture['encounterId'])
            ->where('patient.photoUrl', null)
            ->where('entry.id', $ticket->id)
            ->where('canStartService', false)
            ->where('canRecordVitals', true)
            ->where('canRecordTriage', true));
});

test('triage assessment is locked until the called patient is confirmed present and service starts', function () {
    $fixture = nurseStationFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $room = TenantContextScope::run($tenantId, $facilityId, fn (): Room => Room::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'name' => 'Triage Room',
        'code' => 'TRIAGE-1',
        'status' => 'ACTIVE',
    ]));
    queueAssignRoom($tenantId, $facilityId, (string) $fixture['queue']->id, (string) $room->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $fixture['queue']->id, (string) $room->id, (string) $fixture['user']->id);

    $this->post(route('clinical.encounters.vitals.record', $fixture['encounterId']), [
        'patient_id' => $fixture['patientId'],
        'temperature' => '37.2',
    ])->assertRedirect()->assertSessionHasErrors('vitals_error');
    $this->post(route('clinical.encounters.triage.record', $fixture['encounterId']), [
        'patient_id' => $fixture['patientId'],
        'chief_complaint' => 'Fever',
        'triage_priority' => 'NORMAL',
    ])->assertRedirect()->assertSessionHasErrors('triage_error');
    $this->from('/workspaces/nurse-station/entries/'.$fixture['ticket']->id)
        ->post(route('nurse-station.entries.start', $fixture['ticket']->id), [
            'return_to' => '/workspaces/nurse-station/entries/'.$fixture['ticket']->id,
        ])->assertRedirect('/workspaces/nurse-station/entries/'.$fixture['ticket']->id)
        ->assertSessionHasErrors('start');

    $called = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => app(QueueingApi::class)->call(new CallTicketDto($fixture['ticket']->id, $room->id)),
    );
    expect($called->status)->toBe('CALLED');

    $this->get(route('nurse-station.entries.show', $fixture['ticket']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canStartService', true)
            ->where('canRecordVitals', true)
            ->where('canRecordTriage', true));

    $this->post(route('nurse-station.entries.start', $fixture['ticket']->id), [
        'return_to' => '/workspaces/nurse-station/entries/'.$fixture['ticket']->id,
    ])->assertRedirect('/workspaces/nurse-station/entries/'.$fixture['ticket']->id)
        ->assertSessionMissing('status');

    $this->get(route('nurse-station.entries.show', $fixture['ticket']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entry.status', 'IN_SERVICE')
            ->where('canStartService', false)
            ->where('canRecordVitals', true)
            ->where('canRecordTriage', true));

    $this->post(route('clinical.encounters.vitals.record', $fixture['encounterId']), [
        'patient_id' => $fixture['patientId'],
        'temperature' => '37.2',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('clinical.encounters.triage.record', $fixture['encounterId']), [
        'patient_id' => $fixture['patientId'],
        'chief_complaint' => 'Fever',
        'triage_priority' => 'NORMAL',
    ])->assertRedirect()->assertSessionHasNoErrors();
});

test('generic queue action cannot start service for a triage ticket', function () {
    $fixture = nurseStationFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $room = TenantContextScope::run($tenantId, $facilityId, fn (): Room => Room::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'name' => 'Triage Room',
        'code' => 'TRIAGE-1',
        'status' => 'ACTIVE',
    ]));
    queueAssignRoom($tenantId, $facilityId, (string) $fixture['queue']->id, (string) $room->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $fixture['queue']->id, (string) $room->id, (string) $fixture['user']->id);
    TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn () => app(QueueingApi::class)->call(new CallTicketDto($fixture['ticket']->id, $room->id)),
    );

    $this->post(route('service-queues.entries.start', ['entry' => $fixture['ticket']->id]))
        ->assertRedirect()
        ->assertSessionHasErrors('start');

    $status = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => QueueEntry::query()->where('id', $fixture['ticket']->id)->value('status')->value,
    );
    expect($status)->toBe('CALLED');
});

test('nurse assessment exposes the protected photo route when a patient photo exists', function (): void {
    $fixture = nurseStationFixture();
    $photoDocumentId = (string) Str::uuid7();

    TenantContextScope::run(
        (string) $fixture['tenant']->id,
        (string) $fixture['facility']->id,
        fn () => DB::table('pat_patients')
            ->where('id', $fixture['patientId'])
            ->update(['photo_document_id' => $photoDocumentId]),
    );

    $this->get(route('nurse-station.entries.show', $fixture['ticket']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('patient.photoUrl', route('patient.photo', ['patientId' => $fixture['patientId']])));
});

test('nurse assessment offers previous and next active triage patients', function () {
    $fixture = nurseStationFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    [$nextPatientId, $nextEncounterId] = clinicalPatientWithEncounter(
        $tenantId,
        $facilityId,
        (string) $fixture['user']->id,
    );
    $nextTicket = TenantContextScope::run($tenantId, $facilityId, fn () => app(QueueingApi::class)->place(
        new PlaceInQueueDto(
            encounterId: $nextEncounterId,
            patientId: $nextPatientId,
            queueId: $fixture['queue']->id,
            priority: 'ROUTINE',
        ),
    ));
    TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('qms_queue_entries')
        ->where('id', $nextTicket->id)
        ->update(['created_at' => now()->addMinute()]));

    $this->get(route('nurse-station.entries.show', $fixture['ticket']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('queueNavigation.position', 1)
            ->where('queueNavigation.total', 2)
            ->where('queueNavigation.previous', null)
            ->where('queueNavigation.next.id', $nextTicket->id)
            ->where('queueNavigation.next.ticketNumber', $nextTicket->ticketNumber));

    $this->get(route('nurse-station.entries.show', $nextTicket->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('queueNavigation.position', 2)
            ->where('queueNavigation.total', 2)
            ->where('queueNavigation.previous.id', $fixture['ticket']->id)
            ->where('queueNavigation.next', null));
});

test('nurse station refuses non-triage queue tickets', function () {
    $fixture = nurseStationFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $consultationQueue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'CONSULT',
        'name' => 'General Consultation',
        'service_capability' => ServiceCapability::CONSULTATION->value,
        'ticket_prefix' => 'C',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => app(QueueRepository::class)->save($consultationQueue));
    $ticket = TenantContextScope::run($tenantId, $facilityId, fn () => app(QueueingApi::class)->place(
        new PlaceInQueueDto(
            encounterId: $fixture['encounterId'],
            patientId: $fixture['patientId'],
            queueId: $consultationQueue->id,
            priority: 'ROUTINE',
        ),
    ));

    $this->get(route('nurse-station.entries.show', $ticket->id))->assertNotFound();
});

test('triaged patient can be handed off to a configured consultation queue with the same encounter and priority', function () {
    $fixture = nurseStationFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $consultationQueue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'CONSULT',
        'name' => 'General OPD Consultation',
        'service_capability' => ServiceCapability::CONSULTATION->value,
        'ticket_prefix' => 'C',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => app(QueueRepository::class)->save($consultationQueue));
    nurseStartTriageService($fixture);

    $this->post(route('clinical.encounters.triage.record', $fixture['encounterId']), [
        'patient_id' => $fixture['patientId'],
        'chief_complaint' => 'Fever and cough',
        'triage_priority' => 'HIGH',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->post(route('nurse-station.entries.handoff', $fixture['ticket']->id), [
        'destination_queue_id' => $consultationQueue->id,
        'reason' => 'Nursing assessment completed',
    ])->assertRedirect(route('nurse-station.index'));

    $source = TenantContextScope::run($tenantId, $facilityId, fn () => QueueEntry::query()->findOrFail($fixture['ticket']->id));
    $destination = TenantContextScope::run($tenantId, $facilityId, fn () => QueueEntry::query()
        ->where('queue_id', $consultationQueue->id)
        ->firstOrFail());
    expect($source->status->value)->toBe('COMPLETED')
        ->and($destination->patient_id)->toBe($fixture['patientId'])
        ->and($destination->encounter_id)->toBe($fixture['encounterId'])
        ->and($destination->priority->value)->toBe('PRIORITY');
});

test('failed priority update rolls back the consultation handoff and shows an error', function () {
    $fixture = nurseStationFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $consultationQueue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'CONSULT',
        'name' => 'General OPD Consultation',
        'service_capability' => ServiceCapability::CONSULTATION->value,
        'ticket_prefix' => 'C',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => app(QueueRepository::class)->save($consultationQueue));
    nurseStartTriageService($fixture);

    $this->post(route('clinical.encounters.triage.record', $fixture['encounterId']), [
        'patient_id' => $fixture['patientId'],
        'chief_complaint' => 'Fever and cough',
        'triage_priority' => 'HIGH',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $queueingApi = Mockery::mock(QueueingApi::class);
    $realQueueingApi = app(QueueingApi::class);
    $queueingApi->shouldReceive('transfer')
        ->once()
        ->andReturnUsing(fn ($dto) => $realQueueingApi->transfer($dto));
    $queueingApi->shouldReceive('updatePriority')
        ->once()
        ->andReturn(null);
    $this->app->instance(QueueingApi::class, $queueingApi);

    $this->from(route('nurse-station.entries.show', $fixture['ticket']->id))
        ->post(route('nurse-station.entries.handoff', $fixture['ticket']->id), [
            'destination_queue_id' => $consultationQueue->id,
            'reason' => 'Nursing assessment completed',
        ])
        ->assertRedirect(route('nurse-station.entries.show', $fixture['ticket']->id))
        ->assertSessionHasErrors('handoff_error');

    $entries = TenantContextScope::run($tenantId, $facilityId, fn () => QueueEntry::query()
        ->where('encounter_id', $fixture['encounterId'])
        ->get());
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->id)->toBe($fixture['ticket']->id)
        ->and($entries[0]->status->value)->toBe('IN_SERVICE')
        ->and($entries[0]->priority->value)->toBe('ROUTINE');
});

test('nurse handoff is blocked until a current triage assessment exists', function () {
    $fixture = nurseStationFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $consultationQueue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'CONSULT',
        'name' => 'General OPD Consultation',
        'service_capability' => ServiceCapability::CONSULTATION->value,
        'ticket_prefix' => 'C',
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => app(QueueRepository::class)->save($consultationQueue));
    nurseStartTriageService($fixture);

    $this->from(route('nurse-station.entries.show', $fixture['ticket']->id))
        ->post(route('nurse-station.entries.handoff', $fixture['ticket']->id), [
            'destination_queue_id' => $consultationQueue->id,
            'reason' => 'Nursing assessment completed',
        ])
        ->assertRedirect(route('nurse-station.entries.show', $fixture['ticket']->id))
        ->assertSessionHasErrors('handoff_error');

    $openEntries = TenantContextScope::run($tenantId, $facilityId, fn () => QueueEntry::query()->where('encounter_id', $fixture['encounterId'])->count());
    expect($openEntries)->toBe(1);
});
