<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RecordTriageAssessment;
use App\Domains\Clinical\Contracts\Event\TriageAssessmentRecorded;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\TriageAssessmentContextMismatch;
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
 | PHASE 6 (C.9) gate — RecordTriageAssessment end-to-end through the real
 | CommandBus (IC §5): authorize -> PatientApi -> EncounterApi -> patient-state
 | + encounter-state gates -> optional C.2 vitals provenance -> RECORDED row ->
 | versioned save -> TriageAssessmentRecorded outbox + clinical.triage.recorded
 | audit (ADR-105, ADR-132, ADR-133, ADR-135, ADR-145).
 */

uses(RefreshDatabase::class);

test('an active encounter -> RECORDED triage: context copied, coded priority, PHI-free event + audit (no chief complaint anywhere)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $id = clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'chiefComplaint' => 'Severe chest pain radiating to left arm',
        'triagePriority' => 'emergency',
        'acuity' => 'ESI-2',
        'consciousness' => 'ALERT',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->where('id', $id)->first());
    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)          // from the encounter context
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)
        ->and($row->author_user_id)->toBe((string) $user->id)
        ->and($row->authored_context)->toBe('NURSING')                  // helper default
        ->and($row->chief_complaint)->toBe('Severe chest pain radiating to left arm')
        ->and($row->triage_priority)->toBe('EMERGENCY')                 // normalized
        ->and($row->acuity)->toBe('ESI-2')
        ->and($row->consciousness)->toBe('ALERT')
        ->and($row->source_vitals_observation_id)->toBeNull()
        ->and($row->superseded_at)->toBeNull()
        ->and((int) $row->version)->toBe(0)
        ->and(property_exists($row, 'status'))->toBeFalse();

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', TriageAssessmentRecorded::class)->first());
    expect($outbox->event_name)->toBe('clinical.TriageAssessmentRecorded');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId',
        'triageAssessmentId', 'triagePriority', 'occurredAt', 'correlationId', 'causationId',
    ])
        ->and($payload['triagePriority'])->toBe('EMERGENCY');
    // PHI-free: no chief complaint / acuity / consciousness anywhere in the payload
    expect((string) $outbox->payload)->not->toContain('chest pain')
        ->and((string) $outbox->payload)->not->toContain('ESI-2')
        ->and((string) $outbox->payload)->not->toContain('ALERT');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.triage.recorded')->first());
    expect($audit->target_type)->toBe('triage_assessment')
        ->and($audit->target_id)->toBe($id)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([])
        ->and((string) $audit->metadata)->not->toContain('chest pain');
});

test('ARRIVED and COMPLETED encounters both permit triage; PLANNED / CANCELLED / ABANDONED / LWBS / TRANSFERRED are rejected (ADR-135 parity)', function (EncounterStatus $status, bool $permitted) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus($status)->create(['disposition' => $status === EncounterStatus::Completed ? 'DISCHARGED' : null])->id);

    if ($permitted) {
        $id = clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
        expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->where('id', $id)->exists()))->toBeTrue();

        return;
    }

    expect(fn () => clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(EncounterNotEligibleForClinicalRecord::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->count()))->toBe(0);
})->with([
    'ARRIVED' => [EncounterStatus::Arrived, true],
    'ACTIVE' => [EncounterStatus::Active, true],
    'COMPLETED' => [EncounterStatus::Completed, true],
    'PLANNED' => [EncounterStatus::Planned, false],
    'CANCELLED' => [EncounterStatus::Cancelled, false],
    'ABANDONED' => [EncounterStatus::Abandoned, false],
    'LEFT_WITHOUT_BEING_SEEN' => [EncounterStatus::LeftWithoutBeingSeen, false],
    'TRANSFERRED' => [EncounterStatus::Transferred, false],
]);

test('a provisional ACTIVE patient is permitted (ADR-119 — emergency triage of a provisional identity)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id, ['provisional' => true])->patientId;

    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus(EncounterStatus::Arrived)->create()->id);

    $id = clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->where('id', $id)->exists()))->toBeTrue();
});

test('triage is rejected for an INACTIVE / DECEASED / MERGED patient (409)', function (string $patientStatus) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->update(['status' => $patientStatus]));

    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus(EncounterStatus::Arrived)->create()->id);

    expect(fn () => clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(PatientNotEligibleForClinicalRecord::class);
})->with(['INACTIVE', 'DECEASED', 'MERGED']);

test('a patient / encounter mismatch -> TriageAssessmentContextMismatch (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [, $encounterA] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $patientB = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    try {
        clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientB, $encounterA, (string) $user->id);
        test()->fail('expected TriageAssessmentContextMismatch');
    } catch (TriageAssessmentContextMismatch $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('triage_assessment_context_mismatch');
    }
});

test('an optional sourceVitalsObservationId is accepted when it is a C.2 observation on the SAME encounter, and 409s otherwise', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    [, $otherEncounter] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $vitalsSameEncounter = clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    $id = clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'sourceVitalsObservationId' => $vitalsSameEncounter,
    ]);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->where('id', $id)->value('source_vitals_observation_id')))
        ->toBe($vitalsSameEncounter);

    // a real C.2 observation, but on a different encounter -> mismatch
    $otherPatientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) DB::table('enc_encounters')->where('id', $otherEncounter)->value('patient_id'));
    $vitalsOtherEncounter = clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $otherPatientId, $otherEncounter, (string) $user->id);
    expect(fn () => clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'sourceVitalsObservationId' => $vitalsOtherEncounter,
    ]))->toThrow(TriageAssessmentContextMismatch::class);

    // an unknown vitals id -> NotFound
    expect(fn () => clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'sourceVitalsObservationId' => (string) Str::uuid7(),
    ]))->toThrow(NotFound::class);
});

// ── authorization ──────────────────────────────────────────────────

test('a caller without clinical.triage.record is denied (default deny) — no row / event / audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.triage.read', 'patient.patients.read', 'patient.patients.create',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update',
    ]);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->count()))->toBe(0);
});

test('an encounter at a facility outside the actor\'s scope -> NotFound (not a disclosure)', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facilityA->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->forPatient($patientId)
        ->withStatus(EncounterStatus::Arrived)->create()->id);

    expect(fn () => clinicalRecordTriage((string) $tenant->id, (string) $facilityA->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->count()))->toBe(0);
});

test('care-team membership is NOT required — an author on no care team may triage on key + scope alone', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $id = clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->where('id', $id)->exists()))->toBeTrue();
});

test('backdating > 24h with a reason -> the reason is on the audit row, not on clin_triage_assessments', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $id = clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'occurredAt' => CarbonImmutable::now()->subDays(2)->toISOString(),
        'reason' => 'entered from the paper triage form after the shift',
    ]);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'clinical.triage.recorded')->first());
    expect($audit->reason)->toBe('entered from the paper triage form after the shift');

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->where('id', $id)->first());
    expect($row->reason)->toBeNull()
        ->and(CarbonImmutable::parse($row->occurred_at)->lessThan(CarbonImmutable::parse($row->recorded_at)))->toBeTrue();
});

test('RecordTriageAssessment rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new RecordTriageAssessment(
        triageAssessmentId: 'nope', actorId: (string) Str::uuid7(), patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(), chiefComplaint: 'x', triagePriority: 'LOW',
    ))->toThrow(InvalidArgumentException::class);
});
