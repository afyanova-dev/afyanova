<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RecordVitalSigns;
use App\Domains\Clinical\Contracts\Event\VitalSignsRecorded;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\VitalSignsContextMismatch;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
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
 | PHASE 6 (C.2) gate — RecordVitalSigns end-to-end through the real CommandBus
 | (IC §5): authorize -> PatientApi::getPatientSummary ->
 | EncounterApi::getEncounterContext -> patient-state + encounter-state gates ->
 | RECORDED aggregate -> versioned save -> VitalSignsRecorded outbox +
 | clinical.vitals.recorded audit (ADR-132, ADR-133, ADR-135, ADR-138).
 */

uses(RefreshDatabase::class);

// ── happy path ─────────────────────────────────────────────────────

test('an active encounter -> a RECORDED observation: nine columns + context + times, version 0, VitalSignsRecorded (PHI-free) + clinical.vitals.recorded audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $observationId = clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'measurements' => VitalSigns::of(
            temperature: 38.4, heartRate: 104, respiratoryRate: 22,
            systolicBp: 138, diastolicBp: 88, oxygenSaturation: 94,
            weight: 80.2, height: 178.0, painScore: 6,
        ),
        'authoredContext' => 'NURSING',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $observationId)->first());
    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)       // from the encounter context
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)
        ->and($row->author_user_id)->toBe((string) $user->id)
        ->and($row->authored_context)->toBe('NURSING')
        ->and((float) $row->temperature)->toBe(38.4)
        ->and((int) $row->heart_rate)->toBe(104)
        ->and((int) $row->respiratory_rate)->toBe(22)
        ->and((int) $row->systolic_bp)->toBe(138)
        ->and((int) $row->diastolic_bp)->toBe(88)
        ->and((int) $row->oxygen_saturation)->toBe(94)
        ->and((float) $row->weight)->toBe(80.2)
        ->and((float) $row->height)->toBe(178.0)
        ->and((int) $row->pain_score)->toBe(6)
        ->and($row->supersedes_observation_id)->toBeNull()
        ->and($row->superseded_at)->toBeNull()
        ->and($row->reason)->toBeNull()
        ->and((int) $row->version)->toBe(0)
        ->and($row->occurred_at)->not->toBeNull()
        ->and($row->recorded_at)->not->toBeNull();

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', VitalSignsRecorded::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('clinical.VitalSignsRecorded')
        ->and($outbox->published_at)->toBeNull();

    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    // PHI-free (ADR-136 / ADR-138): asserting the exact key set is the robust
    // check — there is no measurement-value / unit field. (A raw-string substring
    // scan for a short numeric value like "104" collides with hex/timestamp
    // fragments of the random identifiers in the payload.)
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'vitalSignsObservationId', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['vitalSignsObservationId'])->toBe($observationId);
    // distinctive field-name / context tokens cannot appear as identifier fragments
    foreach (['temperature', 'heart_rate', 'NURSING'] as $needle) {
        expect((string) $outbox->payload)->not->toContain($needle);
    }

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.vitals.recorded')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe((string) $user->id)
        ->and($audit->target_type)->toBe('vital_signs_observation')
        ->and($audit->target_id)->toBe($observationId)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]); // empty — carries no measurement value
});

test('a single measurement (temperature only) is a valid observation', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $observationId = clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'measurements' => VitalSigns::of(temperature: 37.0),
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $observationId)->first());
    expect((float) $row->temperature)->toBe(37.0)
        ->and($row->heart_rate)->toBeNull()
        ->and($row->pain_score)->toBeNull();
});

test('an ARRIVED and a COMPLETED encounter both permit recording', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus($status)->create(['disposition' => $status === EncounterStatus::Completed ? 'DISCHARGED' : null])->id);

    $observationId = clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $observationId)->exists()))->toBeTrue();
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

    $observationId = clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $observationId)->exists()))->toBeTrue();
});

test('backdating > 24h with a reason -> the reason is on the audit row, never a clin_ column', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $observationId = clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'occurredAt' => CarbonImmutable::now()->subDays(2)->toISOString(),
        'reason' => 'transcribed from the paper obs chart',
    ]);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.vitals.recorded')->first());
    expect($audit->reason)->toBe('transcribed from the paper obs chart');

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $observationId)->first());
    expect($row->reason)->toBeNull(); // the `reason` column is for corrections only
    expect(CarbonImmutable::parse($row->occurred_at)->lessThan(CarbonImmutable::parse($row->recorded_at)))->toBeTrue();
});

// ── encounter-state gate ───────────────────────────────────────────

test('recording is rejected for a PLANNED / CANCELLED / ABANDONED / LEFT_WITHOUT_BEING_SEEN / TRANSFERRED encounter (409)', function (EncounterStatus $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus($status)->create()->id);

    try {
        clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
        test()->fail("expected rejection for encounter status {$status->value}");
    } catch (EncounterNotEligibleForClinicalRecord $e) {
        expect($e->httpStatus())->toBe(409);
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->count()))->toBe(0);
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

    expect(fn () => clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(PatientNotEligibleForClinicalRecord::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->count()))->toBe(0);
})->with(['INACTIVE', 'DECEASED', 'MERGED']);

// ── not found / mismatch / facility ───────────────────────────────

test('an unknown patient id -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalRecordVitals((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);
});

test('an unknown encounter id -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('a patient / encounter mismatch -> VitalSignsContextMismatch (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [, $encounterA] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $patientB = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    try {
        clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientB, $encounterA, (string) $user->id);
        test()->fail('expected VitalSignsContextMismatch');
    } catch (VitalSignsContextMismatch $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('vital_signs_context_mismatch');
    }
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->count()))->toBe(0);
});

test('an encounter at a facility outside the recorder\'s scope -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facilityA->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->forPatient($patientId)
        ->withStatus(EncounterStatus::Active)->create()->id);

    expect(fn () => clinicalRecordVitals((string) $tenant->id, (string) $facilityA->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->count()))->toBe(0);
});

// ── authorization ──────────────────────────────────────────────────

test('a caller without clinical.vitals.record is denied (default deny), no observation / event / audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.vitals.read',
        'patient.patients.read', 'patient.patients.create',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update',
    ]);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.vitals.recorded')->count()))->toBe(0);
});

test('care-team membership is NOT required — a recorder on no care team but holding the key + scope may record', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $observationId = clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $observationId)->exists()))->toBeTrue();
});

// ── construction validation ───────────────────────────────────────

test('RecordVitalSigns rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new RecordVitalSigns(
        vitalSignsObservationId: 'nope', actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(), encounterId: (string) Str::uuid7(),
        measurements: VitalSigns::of(heartRate: 70),
    ))->toThrow(InvalidArgumentException::class);
});
