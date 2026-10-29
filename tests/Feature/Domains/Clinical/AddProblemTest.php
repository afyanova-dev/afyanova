<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AddProblem;
use App\Domains\Clinical\Contracts\Event\ProblemAdded;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\ProblemContextMismatch;
use App\Domains\Clinical\Domain\ValueObject\ProblemCode;
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
 | PHASE 6 (C.4) gate — AddProblem end-to-end through the real CommandBus
 | (IC §5): authorize -> PatientApi::getPatientSummary ->
 | EncounterApi::getEncounterContext -> patient-state + encounter-state gates ->
 | optional DiagnosisReader provenance -> ACTIVE problem -> versioned save ->
 | ProblemAdded outbox + clinical.problems.added audit (ADR-132, ADR-135,
 | ADR-140).
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/problem.php';

// ── happy path ─────────────────────────────────────────────────────

test('an active encounter -> an ACTIVE problem: origin context + coding + times, version 0, ProblemAdded (code_system only) + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'code' => 'E11.9', 'codeSystem' => 'ICD-10',
        'description' => 'Type 2 diabetes mellitus without complications',
        'onsetDate' => '2020-06-01',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->first());
    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)   // ORIGINATING facility, from the encounter context
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)            // ORIGINATING encounter
        ->and($row->author_user_id)->toBe((string) $user->id)
        ->and($row->authored_context)->toBe('CLINICIAN')
        ->and($row->code)->toBe('E11.9')
        ->and($row->code_system)->toBe('ICD-10')
        ->and($row->description)->toBe('Type 2 diabetes mellitus without complications')
        ->and($row->status)->toBe('ACTIVE')
        ->and($row->onset_date)->toBe('2020-06-01')
        ->and($row->resolved_at)->toBeNull()
        ->and($row->source_diagnosis_id)->toBeNull()
        ->and((int) $row->version)->toBe(0)
        ->and($row->occurred_at)->not->toBeNull()
        ->and($row->recorded_at)->not->toBeNull();

    // no history row on add
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problem_history')->count()))->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', ProblemAdded::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('clinical.ProblemAdded')
        ->and($outbox->published_at)->toBeNull();

    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'problemId', 'problemCodeSystem', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['problemId'])->toBe($problemId)
        ->and($payload['problemCodeSystem'])->toBe('ICD-10');
    foreach (['E11.9', 'diabetes', 'Type 2', '2020-06-01'] as $needle) {
        expect((string) $outbox->payload)->not->toContain($needle);
    }

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.problems.added')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('problem')
        ->and($audit->target_id)->toBe($problemId)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
    foreach (['E11.9', 'diabetes', '2020-06-01'] as $needle) {
        expect((string) $audit->metadata)->not->toContain($needle);
    }
});

test('an un-coded problem (description only) is valid; the event carries a null code_system', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'coding' => ProblemCode::none(),
        'description' => 'Chronic lower back pain',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->first());
    expect($row->code)->toBeNull()->and($row->code_system)->toBeNull()->and($row->status)->toBe('ACTIVE');

    $payload = json_decode((string) Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ProblemAdded::class)->value('payload')), true, 512, JSON_THROW_ON_ERROR);
    expect($payload['problemCodeSystem'])->toBeNull();
});

test('an ARRIVED and a COMPLETED encounter both permit adding a problem', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus($status)->create(['disposition' => $status === EncounterStatus::Completed ? 'DISCHARGED' : null])->id);

    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->exists()))->toBeTrue();
})->with([
    'ARRIVED' => EncounterStatus::Arrived,
    'COMPLETED' => EncounterStatus::Completed,
]);

test('a provisional ACTIVE patient is permitted (ADR-119)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id, ['provisional' => true])->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus(EncounterStatus::Active)->create()->id);

    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->exists()))->toBeTrue();
});

test('backdating occurred_at > 24h with a reason -> the reason is on the audit row, never a clin_ column', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'occurredAt' => CarbonImmutable::now()->subDays(4)->toISOString(),
        'reason' => 'added to the list from an outside records review',
    ]);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.problems.added')->first());
    expect($audit->reason)->toBe('added to the list from an outside records review');

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->first());
    expect(CarbonImmutable::parse($row->occurred_at)->lessThan(CarbonImmutable::parse($row->recorded_at)))->toBeTrue();
});

// ── source-diagnosis provenance (ADR-140 D-I) ─────────────────────

test('a valid sourceDiagnosisId (same tenant, same patient) is stored as plain provenance; no Diagnosis mutation', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $diagnosisId = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    $diagBefore = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $diagnosisId)->first());

    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'sourceDiagnosisId' => $diagnosisId,
    ]);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->value('source_diagnosis_id')))->toBe($diagnosisId);

    // Diagnosis row untouched — no problem_id column, no version bump, no back-reference
    $diagAfter = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $diagnosisId)->first());
    expect((array) $diagAfter)->toBe((array) $diagBefore)
        ->and(array_key_exists('problem_id', (array) $diagAfter))->toBeFalse();
});

test('an unknown / cross-tenant sourceDiagnosisId -> NotFound; nothing persisted', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'sourceDiagnosisId' => (string) Str::uuid7(),
    ]))->toThrow(NotFound::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->count()))->toBe(0);
});

test('a sourceDiagnosisId belonging to a different patient -> ProblemContextMismatch (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientA, $encounterA] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    [$patientB, $encounterB] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $diagnosisB = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientB, $encounterB, (string) $user->id);

    try {
        clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientA, $encounterA, (string) $user->id, ['sourceDiagnosisId' => $diagnosisB]);
        test()->fail('expected ProblemContextMismatch');
    } catch (ProblemContextMismatch $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('problem_context_mismatch');
    }
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->count()))->toBe(0);
});

// ── gates ──────────────────────────────────────────────────────────

test('adding is rejected for a PLANNED / CANCELLED / ABANDONED / LEFT_WITHOUT_BEING_SEEN / TRANSFERRED encounter (409)', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus($status)->create()->id);

    expect(fn () => clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(EncounterNotEligibleForClinicalRecord::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->count()))->toBe(0);
})->with([
    'PLANNED' => EncounterStatus::Planned,
    'CANCELLED' => EncounterStatus::Cancelled,
    'ABANDONED' => EncounterStatus::Abandoned,
    'LEFT_WITHOUT_BEING_SEEN' => EncounterStatus::LeftWithoutBeingSeen,
    'TRANSFERRED' => EncounterStatus::Transferred,
]);

test('adding is rejected for an INACTIVE / DECEASED / MERGED patient (409)', function (string $patientStatus) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->update(['status' => $patientStatus]));
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus(EncounterStatus::Active)->create()->id);

    expect(fn () => clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(PatientNotEligibleForClinicalRecord::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->count()))->toBe(0);
})->with(['INACTIVE', 'DECEASED', 'MERGED']);

test('an unknown patient / unknown encounter -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalAddProblem((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), $encounterId, (string) $user->id))->toThrow(NotFound::class);
    expect(fn () => clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, (string) Str::uuid7(), (string) $user->id))->toThrow(NotFound::class);
});

test('a patient / encounter mismatch -> ProblemContextMismatch (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [, $encounterA] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $patientB = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    try {
        clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientB, $encounterA, (string) $user->id);
        test()->fail('expected ProblemContextMismatch');
    } catch (ProblemContextMismatch $e) {
        expect($e->httpStatus())->toBe(409);
    }
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->count()))->toBe(0);
});

test('an encounter at a facility outside the clinician\'s scope -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = problemFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facilityA->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->forPatient($patientId)
        ->withStatus(EncounterStatus::Active)->create()->id);

    expect(fn () => clinicalAddProblem((string) $tenant->id, (string) $facilityA->id, $patientId, $encounterId, (string) $user->id))->toThrow(NotFound::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->count()))->toBe(0);
});

// ── authorization ──────────────────────────────────────────────────

test('a caller without clinical.problems.record is denied (default deny), no problem / event / audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.problems.read',
        'patient.patients.read', 'patient.patients.create',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update',
    ]);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.problems.added')->count()))->toBe(0);
});

test('care-team membership is NOT required — a clinician on no care team but holding the key + scope may add a problem', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $problemId = clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->exists()))->toBeTrue();
});

// ── idempotency / construction ───────────────────────────────────

test('replaying AddProblem with the same idempotencyKey does not create a second problem', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $command = new AddProblem(
        problemId: (string) Str::uuid7(), actorId: (string) $user->id,
        patientId: $patientId, encounterId: $encounterId,
        coding: ProblemCode::of(code: 'I10', codeSystem: 'ICD-10'), description: 'Hypertension',
        idempotencyKey: 'add-problem-retry-1',
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->count()))->toBe(1);
});

test('AddProblem rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new AddProblem(
        problemId: 'nope', actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(), encounterId: (string) Str::uuid7(),
        coding: ProblemCode::none(), description: 'x',
    ))->toThrow(InvalidArgumentException::class);
});
