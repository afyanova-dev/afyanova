<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AmendClinicalNote;
use App\Domains\Clinical\Application\Command\CreateClinicalNote;
use App\Domains\Clinical\Application\Command\SignClinicalNote;
use App\Domains\Clinical\Contracts\Event\ClinicalNoteCreated;
use App\Domains\Clinical\Domain\Exception\ClinicalNoteContextMismatch;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.1) gate — CreateClinicalNote end-to-end through the real
 | CommandBus (IC §5): authorize -> PatientApi::getPatientSummary ->
 | EncounterApi::getEncounterContext -> patient-state + encounter-state gates ->
 | DRAFT aggregate -> versioned save -> ClinicalNoteCreated outbox +
 | clinical.note_created audit (ADR-132, ADR-133, ADR-134, ADR-135).
 */

uses(RefreshDatabase::class);

// ── happy path ─────────────────────────────────────────────────────

test('an active encounter -> DRAFT note: context copied, times set, version 0, ClinicalNoteCreated (PHI-free) + clinical.note_created audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $noteId = clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'noteType' => 'progress_note', 'title' => 'Ward round', 'body' => 'Afebrile. Plan: continue antibiotics.',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->first());
    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)       // from the encounter context
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)
        ->and($row->author_user_id)->toBe((string) $user->id)
        ->and($row->authored_context)->toBe('CLINICIAN')
        ->and($row->note_type)->toBe('progress_note')
        ->and($row->title)->toBe('Ward round')
        ->and($row->body)->toBe('Afebrile. Plan: continue antibiotics.')
        ->and($row->status)->toBe('DRAFT')
        ->and($row->signed_at)->toBeNull()
        ->and((int) $row->amendment_count)->toBe(0)
        ->and((int) $row->version)->toBe(0)
        ->and($row->occurred_at)->not->toBeNull()
        ->and($row->recorded_at)->not->toBeNull();

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ClinicalNoteCreated::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('clinical.ClinicalNoteCreated')
        ->and($outbox->published_at)->toBeNull();

    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'clinicalNoteId', 'noteType', 'status', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['clinicalNoteId'])->toBe($noteId)
        ->and($payload['status'])->toBe('DRAFT')
        ->and($payload['noteType'])->toBe('progress_note');
    // PHI-free — no title / body / free text anywhere in the payload
    expect((string) $outbox->payload)->not->toContain('Ward round')
        ->and((string) $outbox->payload)->not->toContain('antibiotics');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.note_created')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('clinical_note')
        ->and($audit->target_id)->toBe($noteId)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

// ── nursing note (ADR-144 — increment C.8) ─────────────────────────

test('a NURSING note is created end-to-end: authored_context = NURSING, lifecycle / events / audit identical to a clinician note', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $noteId = clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'noteType' => 'nursing_note', 'title' => 'Nursing admission note', 'body' => 'Patient settled on the ward. Obs stable.',
        'authoredContext' => 'NURSING',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->first());
    expect($row->authored_context)->toBe('NURSING')          // the only difference from a clinician note
        ->and($row->status)->toBe('DRAFT')
        ->and($row->facility_id)->toBe((string) $facility->id)
        ->and($row->author_user_id)->toBe((string) $user->id)
        ->and((int) $row->version)->toBe(0);

    // the ClinicalNoteCreated event is UNCHANGED — same key set as a clinician note, no authoredContext (ADR-144 D-E)
    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ClinicalNoteCreated::class)->first());
    expect($outbox->event_name)->toBe('clinical.ClinicalNoteCreated');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'clinicalNoteId', 'noteType', 'status', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload)->not->toHaveKey('authoredContext')
        ->and($payload['noteType'])->toBe('nursing_note');

    // the audit row is UNCHANGED — same action, no authored_context anywhere (ADR-144 D-E)
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.note_created')->first());
    expect($audit->target_type)->toBe('clinical_note')
        ->and($audit->target_id)->toBe($noteId)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([])
        ->and((string) $audit->metadata)->not->toContain('NURSING');
});

test('a NURSING draft signs, amends and cancels through the same C.1 lifecycle (authored_context never gates a transition)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $noteId = clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'noteType' => 'nursing_note', 'authoredContext' => 'NURSING',
    ]);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($noteId, $user): void {
        $bus = app(CommandBus::class);
        $bus->dispatch(new SignClinicalNote($noteId, (string) $user->id));
        $bus->dispatch(new AmendClinicalNote($noteId, (string) $user->id, 'ADDENDUM', 'Family updated.', 'late information'));
    });

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->first());
    expect($row->status)->toBe('SIGNED')
        ->and((int) $row->amendment_count)->toBe(1)
        ->and($row->authored_context)->toBe('NURSING');
});

test('CreateClinicalNote rejects an out-of-set authoredContext at construction (ADR-144)', function () {
    expect(fn () => new CreateClinicalNote(
        clinicalNoteId: (string) Str::uuid7(), actorId: (string) Str::uuid7(), patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(), noteType: 'nursing_note', title: null, body: 'y', authoredContext: 'PROCEDURE',
    ))->toThrow(InvalidArgumentException::class, 'CLINICIAN or NURSING');
});

test('an ARRIVED encounter and a COMPLETED encounter both permit note creation', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus($status)->create(['disposition' => $status === EncounterStatus::Completed ? 'DISCHARGED' : null])->id);

    $noteId = clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->value('status')))->toBe('DRAFT');
})->with([
    'ARRIVED' => EncounterStatus::Arrived,
    'COMPLETED' => EncounterStatus::Completed,
]);

test('a provisional ACTIVE patient is permitted (ADR-119)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id, ['provisional' => true])->patientId;

    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus(EncounterStatus::Active)->create()->id);

    $noteId = clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->exists()))->toBeTrue();
});

test('backdating more than 24h with a reason -> the reason is written to the audit row, not to clin_notes', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $noteId = clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'occurredAt' => CarbonImmutable::now()->subDays(3)->toISOString(),
        'reason' => 'transcribed from paper chart',
    ]);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.note_created')->first());
    expect($audit->reason)->toBe('transcribed from paper chart');

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->first());
    expect(property_exists($row, 'reason'))->toBeFalse();
    expect(CarbonImmutable::parse($row->occurred_at)->lessThan(CarbonImmutable::parse($row->recorded_at)))->toBeTrue();
});

// ── encounter-state gate ───────────────────────────────────────────

test('note creation is rejected for a PLANNED / CANCELLED / ABANDONED / LEFT_WITHOUT_BEING_SEEN / TRANSFERRED encounter', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus($status)->create()->id);

    try {
        clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
        test()->fail("expected rejection for encounter status {$status->value}");
    } catch (EncounterNotEligibleForClinicalRecord $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('encounter_not_eligible_for_clinical_record');
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->count()))->toBe(0);
})->with([
    'PLANNED' => EncounterStatus::Planned,
    'CANCELLED' => EncounterStatus::Cancelled,
    'ABANDONED' => EncounterStatus::Abandoned,
    'LEFT_WITHOUT_BEING_SEEN' => EncounterStatus::LeftWithoutBeingSeen,
    'TRANSFERRED' => EncounterStatus::Transferred,
]);

// ── patient-state gate ─────────────────────────────────────────────

test('note creation is rejected for an INACTIVE / DECEASED / MERGED patient (409)', function (string $patientStatus) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    // drive the patient to the target lifecycle status directly (Patient commands for this are separate increments)
    Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->update(['status' => $patientStatus]));

    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus(EncounterStatus::Active)->create()->id);

    expect(fn () => clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(PatientNotEligibleForClinicalRecord::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->count()))->toBe(0);
})->with([
    'INACTIVE' => 'INACTIVE',
    'DECEASED' => 'DECEASED',
    'MERGED' => 'MERGED',
]);

// ── not found / cross-tenant / mismatch ────────────────────────────

test('an unknown patient id -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalCreateNote((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);
});

test('an unknown / cross-tenant encounter id -> NotFound', function () {
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = clinicalFixture();
    [, $encounterB] = clinicalPatientWithEncounter((string) $tenantB->id, (string) $facilityB->id, (string) $userB->id);

    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = clinicalFixture();
    [$patientA] = clinicalPatientWithEncounter((string) $tenantA->id, (string) $facilityA->id, (string) $userA->id);

    test()->actingAs($userA);
    expect(fn () => clinicalCreateNote((string) $tenantA->id, (string) $facilityA->id, $patientA, (string) Str::uuid7(), (string) $userA->id))
        ->toThrow(NotFound::class);
    expect(fn () => clinicalCreateNote((string) $tenantA->id, (string) $facilityA->id, $patientA, $encounterB, (string) $userA->id))
        ->toThrow(NotFound::class);
});

test('a patient / encounter mismatch -> ClinicalNoteContextMismatch (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientA, $encounterA] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $patientB = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    try {
        clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientB, $encounterA, (string) $user->id);
        test()->fail('expected ClinicalNoteContextMismatch');
    } catch (ClinicalNoteContextMismatch $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('clinical_note_context_mismatch');
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->count()))->toBe(0);
});

// ── authorization ──────────────────────────────────────────────────

test('a caller without clinical.notes.create is denied (default deny), no note / event / audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.notes.read', 'clinical.notes.sign', 'clinical.notes.amend',
        'patient.patients.read', 'patient.patients.create', 'encounter.encounters.read',
        'encounter.encounters.create', 'encounter.encounters.update',
    ]);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.note_created')->count()))->toBe(0);
});

test('care-team membership is NOT required — an author who is on no care team but holds the key + facility scope may document', function () {
    // Care teams do not exist in C.1; this asserts the author is authorized purely on key + scope.
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $noteId = clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->where('id', $noteId)->exists()))->toBeTrue();
});

test('an encounter at a facility outside the author\'s scope -> NotFound (not a disclosure)', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = clinicalFixture();
    $otherFacility = (string) Str::uuid7();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facilityA->id, (string) $user->id)->patientId;

    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility($otherFacility)->forPatient($patientId)
        ->withStatus(EncounterStatus::Active)->create()->id);

    expect(fn () => clinicalCreateNote((string) $tenant->id, (string) $facilityA->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_notes')->count()))->toBe(0);
});

// ── validation ─────────────────────────────────────────────────────

test('CreateClinicalNote rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new CreateClinicalNote(
        clinicalNoteId: 'nope', actorId: (string) Str::uuid7(), patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(), noteType: 'x', title: null, body: 'y',
    ))->toThrow(InvalidArgumentException::class);
});

test('the note aggregate starts a fresh version 0 (no hard-coded assumptions beyond the bus chain)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $noteId = clinicalCreateNote((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    expect(Tenancy::run((string) $tenant->id, fn () => (int) DB::table('clin_notes')->where('id', $noteId)->value('version')))->toBe(0);

    // the referenced patient really exists (no cross-domain FK, but the reference is real)
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->exists()))->toBeTrue();
});
