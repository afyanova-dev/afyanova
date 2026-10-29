<?php

declare(strict_types=1);

use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../../../tests/Support/queueing.php';
require_once __DIR__.'/../../../../tests/Support/reception.php';

uses(RefreshDatabase::class);

test('one encounter flows from reception through nurse triage and clinician completion', function () {
    $permissions = [
        'clinical.access',
        'clinical.notes.read',
        'clinical.notes.create',
        'clinical.vitals.read',
        'clinical.vitals.record',
        'clinical.triage.read',
        'clinical.triage.record',
        'clinical.diagnoses.read',
        'clinical.diagnoses.record',
        'clinical.plans.read',
        'clinical.plans.record',
        'clinical.consultations.read',
        'clinical.consultations.create',
        'patient.patients.read',
        'patient.patients.create',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
        'encounter.encounters.manage_disposition',
        'encounter.encounters.complete',
        'queue.ticket.view',
        'queue.ticket.place',
        'queue.ticket.call',
        'queue.ticket.service',
        'queue.ticket.transfer',
    ];
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture($permissions);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $patientId = clinicalRegisterPatient($tenantId, $facilityId, (string) $user->id)->patientId;

    $triageQueue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'TRIAGE',
        'name' => 'OPD Triage',
        'service_capability' => ServiceCapability::TRIAGE->value,
        'ticket_prefix' => 'T',
    ]);
    $consultationQueue = new Queue([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'code' => 'CONSULT',
        'name' => 'General OPD Consultation',
        'service_capability' => ServiceCapability::CONSULTATION->value,
        'ticket_prefix' => 'C',
    ]);
    $queueRepository = app(QueueRepository::class);
    TenantContextScope::run($tenantId, $facilityId, function () use ($queueRepository, $triageQueue, $consultationQueue): void {
        $queueRepository->save($triageQueue);
        $queueRepository->save($consultationQueue);
    });
    $triageRoom = queueTestRoom($tenantId, $facilityId, 'TRIAGE-1');
    queueAssignRoom($tenantId, $facilityId, $triageQueue->id, $triageRoom->id);
    queueAssignRoomStaff($tenantId, $facilityId, $triageQueue->id, $triageRoom->id, (string) $user->id);
    $consultationRoom = queueTestRoom($tenantId, $facilityId, 'CONSULT-1');
    queueAssignRoom($tenantId, $facilityId, $consultationQueue->id, $consultationRoom->id);
    queueAssignRoomStaff($tenantId, $facilityId, $consultationQueue->id, $consultationRoom->id, (string) $user->id);

    $this->withSession(['active_facility_id' => $facilityId])
        ->post(route('reception.check-in'), [
            'patient_id' => $patientId,
            'encounter_type' => EncounterType::Outpatient->value,
            'queue_id' => $triageQueue->id,
            'priority' => 'ROUTINE',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $encounter = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('enc_encounters')
        ->where('patient_id', $patientId)->firstOrFail());
    $triageTicket = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('qms_queue_entries')
        ->where('encounter_id', $encounter->id)->firstOrFail());
    expect($encounter->status)->toBe('ARRIVED')
        ->and($triageTicket->queue_id)->toBe($triageQueue->id);

    $this->post(route('service-queues.entries.call', $triageTicket->id), [
        'room' => $triageRoom->id,
        'return_to' => route('service-queues.index'),
    ])->assertRedirect(route('service-queues.index'))->assertSessionHasNoErrors();
    $this->post(route('nurse-station.entries.start', $triageTicket->id), [
        'return_to' => '/workspaces/nurse-station/entries/'.$triageTicket->id,
    ])->assertRedirect('/workspaces/nurse-station/entries/'.$triageTicket->id)->assertSessionHasNoErrors();

    $this->post(route('clinical.encounters.vitals.record', $encounter->id), [
        'patient_id' => $patientId,
        'temperature' => '38.1',
        'heart_rate' => '104',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('clinical.encounters.triage.record', $encounter->id), [
        'patient_id' => $patientId,
        'chief_complaint' => 'Fever and cough',
        'triage_priority' => 'HIGH',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->get(route('reception.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('encounters.0.encounterId', $encounter->id)
            ->where('encounters.0.queueStatus', 'IN_SERVICE')
            ->where('encounters.0.queueName', 'OPD Triage')
            ->where('encounters.0.ticketNumber', $triageTicket->ticket_number)
            ->where('encounters.0.vitalsRecorded', true)
            ->where('encounters.0.triageRecorded', true)
            ->where('metrics.inServicePatients', 1));

    $receptionOnlyUser = createReceptionUser($tenantId, $facilityId, [
        'encounter.encounters.read',
        'patient.patients.read',
        'clinical.documentation_status.read',
    ]);
    $this->actingAs($receptionOnlyUser)
        ->withSession(['active_facility_id' => $facilityId])
        ->get(route('reception.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('encounters.0.queueStatus', null)
            ->where('encounters.0.vitalsRecorded', true)
            ->where('encounters.0.triageRecorded', true)
            ->where('restricted', fn ($restricted) => $restricted->contains('tickets')));

    $this->actingAs($user)->withSession(['active_facility_id' => $facilityId]);
    $this->post(route('nurse-station.entries.handoff', $triageTicket->id), [
        'destination_queue_id' => $consultationQueue->id,
        'reason' => 'Nursing assessment completed',
    ])->assertRedirect(route('nurse-station.index'));

    $consultationTicket = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('qms_queue_entries')
        ->where('queue_id', $consultationQueue->id)->firstOrFail());
    expect($consultationTicket->encounter_id)->toBe($encounter->id)
        ->and($consultationTicket->patient_id)->toBe($patientId)
        ->and($consultationTicket->priority)->toBe('PRIORITY');

    $this->get(route('clinician.entries.show', $consultationTicket->id))->assertOk();
    $this->post(route('reception.encounters.start', $encounter->id))->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('clinical.encounters.notes.record', $encounter->id), [
        'patient_id' => $patientId,
        'note_type' => 'CONSULTATION',
        'body' => 'Patient assessed after nursing triage.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('clinical.encounters.diagnoses.record', $encounter->id), [
        'patient_id' => $patientId,
        'description' => 'Acute febrile illness',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('clinical.encounters.plans.create', $encounter->id), [
        'patient_id' => $patientId,
        'plan_type' => 'FOLLOW_UP',
        'clinical_intent' => 'Review the patient in two days.',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('reception.encounters.disposition', $encounter->id), [
        'disposition' => 'FOLLOW_UP',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('reception.encounters.complete', $encounter->id))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $completed = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('enc_encounters')
        ->where('id', $encounter->id)->firstOrFail());
    expect($completed->status)->toBe('COMPLETED')
        ->and($completed->disposition)->toBe('FOLLOW_UP');
});
