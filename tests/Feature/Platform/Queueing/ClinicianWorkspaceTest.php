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

require_once __DIR__.'/../../../Support/allergy.php';
require_once __DIR__.'/../../../Support/laboratory.php';
require_once __DIR__.'/../../../Support/problem.php';

uses(RefreshDatabase::class);

function clinicianWorkspaceFixture(?array $extraPermissions = null): array
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

    if ($extraPermissions !== null) {
        $permissions = array_values(array_unique([...$permissions, ...$extraPermissions]));
    }

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
            ->where('entries.0.id', $fixture['ticket']->id)
            ->where('entries.0.patientId', $fixture['patientId']));

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

test('clinician encounter provides active diagnosis catalogue entries', function (): void {
    $fixture = clinicianWorkspaceFixture();
    $createdAt = now();

    DB::table('clin_diagnosis_catalog')->insert([
        [
            'id' => '10000000-0000-7000-8000-000000000001',
            'code' => 'B54',
            'code_system' => 'ICD-10',
            'name' => 'Unspecified malaria',
            'aliases' => 'Malaria fever',
            'category' => 'INFECTIOUS',
            'is_favourite' => true,
            'active' => true,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ],
        [
            'id' => '10000000-0000-7000-8000-000000000002',
            'code' => 'D64.9',
            'code_system' => 'ICD-10',
            'name' => 'Anaemia, unspecified',
            'aliases' => 'Anaemia',
            'category' => 'HAEMATOLOGY',
            'is_favourite' => false,
            'active' => false,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ],
    ]);

    $this->get(route('clinician.entries.show', $fixture['ticket']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('diagnosisCatalog', 1)
            ->where('diagnosisCatalog.0.code', 'B54')
            ->where('diagnosisCatalog.0.codeSystem', 'ICD-10')
            ->where('diagnosisCatalog.0.aliases', 'Malaria fever'));
});

test('clinician can browse and filter the active diagnosis catalogue', function (): void {
    $fixture = clinicianWorkspaceFixture();
    $createdAt = now();

    DB::table('clin_diagnosis_catalog')->insert([
        [
            'id' => '20000000-0000-7000-8000-000000000001',
            'code' => 'B54',
            'code_system' => 'ICD-10',
            'name' => 'Unspecified malaria',
            'aliases' => 'Malaria fever',
            'category' => 'INFECTIOUS',
            'is_favourite' => false,
            'active' => true,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ],
        [
            'id' => '20000000-0000-7000-8000-000000000002',
            'code' => 'D64.9',
            'code_system' => 'ICD-10',
            'name' => 'Anaemia, unspecified',
            'aliases' => 'Anaemia',
            'category' => 'HAEMATOLOGY',
            'is_favourite' => false,
            'active' => true,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ],
    ]);

    $this->get(route('clinician.diagnoses.index', [
        'search' => 'fever',
        'category' => 'INFECTIOUS',
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinician/DiagnosisCatalog')
            ->where('filters.search', 'fever')
            ->where('filters.category', 'INFECTIOUS')
            ->has('diagnoses.data', 1)
            ->where('diagnoses.data.0.code', 'B54')
            ->where('diagnoses.data.0.name', 'Unspecified malaria'));
});

test('staff without diagnosis read permission cannot browse the diagnosis catalogue', function (): void {
    ['user' => $user, 'facility' => $facility] = clinicalFixture([
        'clinical.access',
        'clinical.consultations.read',
    ]);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get(route('clinician.diagnoses.index'))
        ->assertForbidden();
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

test('clinician catalog lists only active AVAILABLE laboratory tests and panels', function (): void {
    $fixture = clinicianWorkspaceFixture(extraPermissions: ['laboratory.orders.create']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $available = seedLabTestDefinition($tenantId, ['code' => 'READY', 'name' => 'Ready test']);
    seedLabTestDefinition($tenantId, [
        'code' => 'REFERRAL',
        'name' => 'Referral test',
        'availability_status' => 'REFERRAL_ONLY',
    ]);
    seedLabTestDefinition($tenantId, [
        'code' => 'TEMP',
        'name' => 'Temporarily unavailable test',
        'availability_status' => 'TEMPORARILY_UNAVAILABLE',
    ]);
    seedLabTestDefinition($tenantId, ['code' => 'INACTIVE', 'name' => 'Inactive test', 'active' => false]);
    $readyPanel = seedLabPanel($tenantId, 'READY_PANEL', [(string) $available->id]);
    $referral = seedLabTestDefinition($tenantId, [
        'code' => 'REFERRAL_PANEL_TEST',
        'name' => 'Referral panel test',
        'availability_status' => 'REFERRAL_ONLY',
    ]);
    seedLabPanel($tenantId, 'MIXED_PANEL', [(string) $available->id, (string) $referral->id]);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->get(route('clinician.entries.show', $fixture['ticket']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Clinician/Show')
            ->where('labTestCatalog.0.code', 'READY')
            ->has('labTestCatalog', 1)
            ->where('labPanelCatalog.0.panelId', $readyPanel)
            ->has('labPanelCatalog', 1));
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

test('clinician documentation draft is encrypted, restored, then cleared by the real note save', function () {
    $fixture = clinicianWorkspaceFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $this->post(route('clinical.encounters.documentation-drafts.save', $fixture['encounterId']), [
        'section' => 'NOTE',
        'patient_id' => $fixture['patientId'],
        'values' => [
            'note_type' => 'CONSULTATION',
            'title' => 'Unfinished consultation',
            'body' => 'Private draft body that must not be stored in plaintext.',
        ],
    ])->assertOk()
        ->assertJsonStructure(['savedAt', 'expiresAt']);

    $draft = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_documentation_drafts')->first());
    expect($draft)->not->toBeNull()
        ->and($draft->payload)->not->toContain('Private draft body')
        ->and($draft->section)->toBe('NOTE')
        ->and($draft->user_id)->toBe((string) $fixture['user']->id);

    $this->get(route('clinician.entries.show', $fixture['ticket']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('drafts.note.values.title', 'Unfinished consultation')
            ->where('drafts.note.values.body', 'Private draft body that must not be stored in plaintext.'));

    $this->post(route('clinical.encounters.notes.record', $fixture['encounterId']), [
        'patient_id' => $fixture['patientId'],
        'note_type' => 'CONSULTATION',
        'title' => 'Finished consultation',
        'body' => 'The note was saved, so the short-lived draft is no longer needed.',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_documentation_drafts')->count()))->toBe(0);
});

test('clinician can discard a documentation draft explicitly', function () {
    $fixture = clinicianWorkspaceFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $this->post(route('clinical.encounters.documentation-drafts.save', $fixture['encounterId']), [
        'section' => 'DIAGNOSIS',
        'patient_id' => $fixture['patientId'],
        'values' => [
            'description' => 'Draft diagnosis to discard',
            'clinical_status' => 'WORKING',
        ],
    ])->assertOk();

    $this->delete(route('clinical.encounters.documentation-drafts.destroy', [
        'encounter' => $fixture['encounterId'],
        'section' => 'DIAGNOSIS',
    ]))->assertNoContent();

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_documentation_drafts')->count()))->toBe(0);
});

test('clinician encounter exposes safety snapshot and recorded documentation read-back', function () {
    $fixture = clinicianWorkspaceFixture([
        'clinical.allergies.read',
        'clinical.allergies.record',
        'clinical.problems.read',
        'clinical.problems.record',
        'clinical.prescribing.read',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    clinicalRecordAllergy($tenantId, $facilityId, $fixture['patientId'], $fixture['encounterId'], (string) $fixture['user']->id, [
        'category' => 'DRUG',
        'substanceName' => 'Penicillin',
        'reactionDescription' => 'Hives and wheeze',
        'severity' => 'SEVERE',
    ]);
    clinicalAddProblem($tenantId, $facilityId, $fixture['patientId'], $fixture['encounterId'], (string) $fixture['user']->id, [
        'description' => 'Essential (primary) hypertension',
    ]);

    $this->post(route('clinical.encounters.notes.record', $fixture['encounterId']), [
        'patient_id' => $fixture['patientId'],
        'note_type' => 'CONSULTATION',
        'title' => 'Initial consultation',
        'body' => 'Patient reports a two-day fever.',
    ])->assertRedirect()->assertSessionHasNoErrors();

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
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->get(route('clinician.entries.show', $fixture['ticket']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canReadAllergies', true)
            ->where('canReadProblems', true)
            ->where('canReadMedications', true)
            ->has('allergies', 1)
            ->where('allergies.0.substanceName', 'Penicillin')
            ->where('allergies.0.severity', 'SEVERE')
            ->has('problems', 1)
            ->where('problems.0.description', 'Essential (primary) hypertension')
            ->has('notes', 1)
            ->where('notes.0.title', 'Initial consultation')
            ->where('notes.0.status', 'DRAFT')
            ->has('diagnoses', 1)
            ->where('diagnoses.0.description', 'Acute febrile illness')
            ->has('plans', 1)
            ->where('plans.0.planType', 'FOLLOW_UP')
            ->has('medications', 0)
            ->where('drafts.note', null)
            ->where('drafts.diagnosis', null)
            ->where('drafts.plan', null));
});

test('clinician encounter exposes orderable lab tests and panels to order creators', function () {
    $fixture = clinicianWorkspaceFixture(['laboratory.orders.create']);
    $tenantId = (string) $fixture['tenant']->id;
    $testDefinition = seedLabTestDefinition($tenantId, [
        'code' => 'FBC',
        'name' => 'Full Blood Count',
    ]);
    $panelMember = seedLabTestDefinition($tenantId, [
        'code' => 'GLU',
        'name' => 'Glucose',
    ]);
    seedLabPanel($tenantId, 'CHEMISTRY', [(string) $panelMember->id]);

    $this->get(route('clinician.entries.show', $fixture['ticket']->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canRecordLabOrders', true)
            ->has('labTestCatalog', 2)
            ->where('labTestCatalog.0.definitionId', (string) $testDefinition->id)
            ->where('labTestCatalog.0.name', 'Full Blood Count')
            ->has('labPanelCatalog', 1)
            ->where('labPanelCatalog.0.code', 'CHEMISTRY'));
});
