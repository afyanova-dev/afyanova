<?php

declare(strict_types=1);

use App\Platform\Queueing\Contracts\DTO\PlaceInQueueDto;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Repository\QueueRepository;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function clinicianWorkspaceFixture(): array
{
    $permissions = [
        'clinical.access',
        'clinical.notes.read',
        'clinical.notes.create',
        'clinical.notes.sign',
        'clinical.consultations.create',
        'clinical.vitals.read',
        'clinical.triage.read',
        'clinical.diagnoses.read',
        'clinical.diagnoses.record',
        'clinical.plans.read',
        'clinical.plans.record',
        'clinical.consultations.read',
        'patient.patients.read',
        'patient.patients.create',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
        'encounter.encounters.manage_disposition',
        'encounter.encounters.complete',
        'queue.ticket.view',
        'queue.ticket.place',
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
        'code' => 'CONSULT',
        'name' => 'General OPD Consultation',
        'service_capability' => ServiceCapability::CONSULTATION->value,
        'ticket_prefix' => 'A',
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

test('nurse with note creation access cannot open the clinician consultation workspace', function () {
    $permissions = [
        'clinical.access',
        'clinical.notes.read',
        'clinical.notes.create',
        'clinical.vitals.read',
        'clinical.triage.read',
        'patient.patients.read',
        'encounter.encounters.read',
        'queue.ticket.view',
    ];
    ['facility' => $facility, 'user' => $user] = clinicalFixture($permissions);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get(route('clinician.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get(route('clinician.entries.show', '00000000-0000-7000-8000-000000000000'))
        ->assertForbidden();
});

test('nurse with general note creation permission cannot create clinician-authored notes', function () {
    $permissions = [
        'clinical.access',
        'clinical.notes.create',
        'clinical.consultations.read',
        'patient.patients.read',
        'patient.patients.create',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
        'queue.ticket.view',
    ];
    ['facility' => $facility, 'user' => $user] = clinicalFixture($permissions);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post(route('clinical.encounters.notes.record', '00000000-0000-7000-8000-000000000000'), [
            'patient_id' => '00000000-0000-7000-8000-000000000000',
            'note_type' => 'CONSULTATION',
            'title' => 'Consultation note',
            'body' => 'This should be denied.',
        ])
        ->assertForbidden();
});

test('clinician queue opens the same encounter with nursing context', function () {
    $fixture = clinicianWorkspaceFixture();

    $this->get(route('clinician.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinician/Index')
            ->has('entries', 1)
            ->where('entries.0.id', $fixture['ticket']->id));

    $this->get(route('clinician.entries.show', $fixture['ticket']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinician/Show')
            ->where('patient.patientId', $fixture['patientId'])
            ->where('patient.photoUrl', null)
            ->where('encounter.encounterId', $fixture['encounterId'])
            ->where('queueNavigation.position', 1)
            ->where('queueNavigation.total', 1)
            ->where('queueNavigation.previous', null)
            ->where('queueNavigation.next', null)
            ->where('canRecordNote', true)
            ->where('canRecordDiagnosis', true)
            ->where('canRecordPlan', true));
});

test('clinician encounter banner navigates among active consultation queue patients', function (): void {
    $fixture = clinicianWorkspaceFixture();
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

    $this->get(route('clinician.entries.show', $fixture['ticket']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('queueNavigation.position', 1)
            ->where('queueNavigation.total', 2)
            ->where('queueNavigation.previous', null)
            ->where('queueNavigation.next.id', $nextTicket->id));

    $this->get(route('clinician.entries.show', $nextTicket->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('queueNavigation.position', 2)
            ->where('queueNavigation.total', 2)
            ->where('queueNavigation.previous.id', $fixture['ticket']->id)
            ->where('queueNavigation.next', null));
});

test('clinician can save note draft, diagnosis and clinical plan against one encounter', function () {
    $fixture = clinicianWorkspaceFixture();
    $noteResponse = $this->post(route('clinical.encounters.notes.record', $fixture['encounterId']), [
        'patient_id' => $fixture['patientId'],
        'note_type' => 'CONSULTATION',
        'title' => 'Initial consultation',
        'body' => 'Patient reports fever for two days.',
        'authored_context' => 'NURSING',
    ]);
    $noteResponse->assertRedirect()->assertSessionHasNoErrors();

    $this->post(route('clinical.encounters.diagnoses.record', $fixture['encounterId']), [
        'patient_id' => $fixture['patientId'],
        'description' => 'Acute febrile illness',
        'code' => 'A99',
        'code_system' => 'LOCAL',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->post(route('clinical.encounters.plans.create', $fixture['encounterId']), [
        'patient_id' => $fixture['patientId'],
        'plan_type' => 'FOLLOW_UP',
        'clinical_intent' => 'Review symptoms in two days.',
        'follow_up' => 'Return to clinic if symptoms worsen.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $note = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_notes')->first());
    $diagnosis = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_diagnoses')->first());
    $plan = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_plans')->first());
    expect($note)->not->toBeNull()
        ->and($note->encounter_id)->toBe($fixture['encounterId'])
        ->and($note->authored_context)->toBe('CLINICIAN')
        ->and($note->status)->toBe('DRAFT')
        ->and($diagnosis->patient_id)->toBe($fixture['patientId'])
        ->and($diagnosis->code)->toBe('A99')
        ->and($plan->encounter_id)->toBe($fixture['encounterId'])
        ->and($plan->plan_type)->toBe('FOLLOW_UP');
});

test('diagnosis rejects an incomplete code and code-system pair', function () {
    $fixture = clinicianWorkspaceFixture();

    $this->from(route('clinician.entries.show', $fixture['ticket']->id))
        ->post(route('clinical.encounters.diagnoses.record', $fixture['encounterId']), [
            'patient_id' => $fixture['patientId'],
            'description' => 'Acute febrile illness',
            'code' => 'A99',
        ])
        ->assertRedirect(route('clinician.entries.show', $fixture['ticket']->id))
        ->assertSessionHasErrors('code_system');

    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    expect(TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_diagnoses')->count()))->toBe(0);
});

test('clinician sets disposition before completing an active encounter', function () {
    $fixture = clinicianWorkspaceFixture();
    $encounterId = $fixture['encounterId'];

    $this->post(route('reception.encounters.disposition', $encounterId), [
        'disposition' => 'FOLLOW_UP',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->post(route('reception.encounters.complete', $encounterId))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $encounter = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('enc_encounters')->where('id', $encounterId)->first());
    expect($encounter->status)->toBe('COMPLETED')
        ->and($encounter->disposition)->toBe('FOLLOW_UP');
});
