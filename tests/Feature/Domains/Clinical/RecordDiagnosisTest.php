<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RecordDiagnosis;
use App\Domains\Clinical\Contracts\Event\DiagnosisRecorded;
use App\Domains\Clinical\Domain\Exception\DiagnosisContextMismatch;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.3) gate — RecordDiagnosis end-to-end through the real CommandBus
 | (IC §5): authorize -> PatientApi::getPatientSummary ->
 | EncounterApi::getEncounterContext -> patient-state + encounter-state gates ->
 | RECORDED aggregate -> versioned save -> DiagnosisRecorded outbox +
 | clinical.diagnoses.recorded audit (ADR-132, ADR-135, ADR-139).
 */

uses(RefreshDatabase::class);

// ── happy path ─────────────────────────────────────────────────────

test('an active encounter -> a RECORDED diagnosis: context + coding + status + times, version 0, DiagnosisRecorded (code_system only, never the code) + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $diagnosisId = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'code' => 'B50.9', 'codeSystem' => 'ICD-10',
        'description' => 'Plasmodium falciparum malaria without complication',
        'clinicalStatus' => 'CONFIRMED',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $diagnosisId)->first());
    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)   // from the encounter context
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)
        ->and($row->author_user_id)->toBe((string) $user->id)
        ->and($row->authored_context)->toBe('CLINICIAN')
        ->and($row->code)->toBe('B50.9')
        ->and($row->code_system)->toBe('ICD-10')
        ->and($row->description)->toBe('Plasmodium falciparum malaria without complication')
        ->and($row->clinical_status)->toBe('CONFIRMED')
        ->and($row->supersedes_diagnosis_id)->toBeNull()
        ->and($row->superseded_at)->toBeNull()
        ->and($row->reason)->toBeNull()
        ->and((int) $row->version)->toBe(0)
        ->and($row->occurred_at)->not->toBeNull()
        ->and($row->recorded_at)->not->toBeNull();

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', DiagnosisRecorded::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('clinical.DiagnosisRecorded')
        ->and($outbox->published_at)->toBeNull();

    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'diagnosisId', 'diagnosisCodeSystem', 'clinicalStatus', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['diagnosisId'])->toBe($diagnosisId)
        ->and($payload['diagnosisCodeSystem'])->toBe('ICD-10')
        ->and($payload['clinicalStatus'])->toBe('CONFIRMED');
    // PHI-free — the code and the description never appear
    foreach (['B50.9', 'Plasmodium', 'falciparum', 'without complication'] as $needle) {
        expect((string) $outbox->payload)->not->toContain($needle);
    }

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.diagnoses.recorded')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('diagnosis')
        ->and($audit->target_id)->toBe($diagnosisId)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
    foreach (['B50.9', 'Plasmodium', 'falciparum'] as $needle) {
        expect((string) $audit->metadata)->not->toContain($needle);
    }
});

test('an un-coded diagnosis (description only) is valid; the event carries a null code_system', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $diagnosisId = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'coding' => DiagnosisCode::none(),
        'description' => 'Clinical malaria, awaiting confirmation',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $diagnosisId)->first());
    expect($row->code)->toBeNull()->and($row->code_system)->toBeNull()->and($row->clinical_status)->toBe('WORKING');

    $payload = json_decode((string) Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', DiagnosisRecorded::class)->value('payload')), true, 512, JSON_THROW_ON_ERROR);
    expect($payload['diagnosisCodeSystem'])->toBeNull();
});

test('an ARRIVED and a COMPLETED encounter both permit recording', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus($status)->create(['disposition' => $status === EncounterStatus::Completed ? 'DISCHARGED' : null])->id);

    $diagnosisId = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $diagnosisId)->exists()))->toBeTrue();
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

    $diagnosisId = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $diagnosisId)->exists()))->toBeTrue();
});

test('backdating > 24h with a reason -> the reason is on the audit row, never a clin_ column', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $diagnosisId = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'occurredAt' => CarbonImmutable::now()->subDays(3)->toISOString(),
        'reason' => 'documented from the referral letter',
    ]);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.diagnoses.recorded')->first());
    expect($audit->reason)->toBe('documented from the referral letter');

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $diagnosisId)->first());
    expect($row->reason)->toBeNull(); // the `reason` column is for corrections only
    expect(CarbonImmutable::parse($row->occurred_at)->lessThan(CarbonImmutable::parse($row->recorded_at)))->toBeTrue();
});

// ── coding rule ────────────────────────────────────────────────────

test('a code without a code_system is rejected at construction (ADR-139 D2)', function () {
    expect(fn () => DiagnosisCode::of(code: 'B54'))->toThrow(InvalidArgumentException::class);
});

// ── encounter-state gate ───────────────────────────────────────────

test('recording is rejected for a PLANNED / CANCELLED / ABANDONED / LEFT_WITHOUT_BEING_SEEN / TRANSFERRED encounter (409)', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus($status)->create()->id);

    try {
        clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
        test()->fail("expected rejection for encounter status {$status->value}");
    } catch (EncounterNotEligibleForClinicalRecord $e) {
        expect($e->httpStatus())->toBe(409);
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->count()))->toBe(0);
})->with([
    'PLANNED' => EncounterStatus::Planned,
    'CANCELLED' => EncounterStatus::Cancelled,
    'ABANDONED' => EncounterStatus::Abandoned,
    'LEFT_WITHOUT_BEING_SEEN' => EncounterStatus::LeftWithoutBeingSeen,
    'TRANSFERRED' => EncounterStatus::Transferred,
]);

// ── patient-state gate ─────────────────────────────────────────────

test('recording is rejected for an INACTIVE / DECEASED / MERGED patient (409)', function (string $patientStatus) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->update(['status' => $patientStatus]));
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus(EncounterStatus::Active)->create()->id);

    expect(fn () => clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(PatientNotEligibleForClinicalRecord::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->count()))->toBe(0);
})->with(['INACTIVE', 'DECEASED', 'MERGED']);

// ── not found / mismatch / facility ───────────────────────────────

test('an unknown patient / unknown encounter -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);
    expect(fn () => clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('a patient / encounter mismatch -> DiagnosisContextMismatch (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [, $encounterA] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $patientB = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    try {
        clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientB, $encounterA, (string) $user->id);
        test()->fail('expected DiagnosisContextMismatch');
    } catch (DiagnosisContextMismatch $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('diagnosis_context_mismatch');
    }
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->count()))->toBe(0);
});

test('an encounter at a facility outside the clinician\'s scope -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facilityA->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->forPatient($patientId)
        ->withStatus(EncounterStatus::Active)->create()->id);

    expect(fn () => clinicalRecordDiagnosis((string) $tenant->id, (string) $facilityA->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->count()))->toBe(0);
});

// ── authorization ──────────────────────────────────────────────────

test('a caller without clinical.diagnoses.record is denied (default deny), no diagnosis / event / audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.diagnoses.read',
        'patient.patients.read', 'patient.patients.create',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update',
    ]);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.diagnoses.recorded')->count()))->toBe(0);
});

test('care-team membership is NOT required — a clinician on no care team but holding the key + scope may diagnose', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $diagnosisId = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $diagnosisId)->exists()))->toBeTrue();
});

// ── construction validation ───────────────────────────────────────

test('RecordDiagnosis rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new RecordDiagnosis(
        diagnosisId: 'nope', actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(), encounterId: (string) Str::uuid7(),
        coding: DiagnosisCode::none(), description: 'x',
    ))->toThrow(InvalidArgumentException::class);
});
