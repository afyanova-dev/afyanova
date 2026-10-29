<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RequestProcedure;
use App\Domains\Clinical\Contracts\Event\ProcedureRequested;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\ProcedureCapabilityUnavailable;
use App\Domains\Clinical\Domain\Exception\ProcedureContextMismatch;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (CP.1) gate — RequestProcedure end-to-end through the real
 | CommandBus (IC §5): authorize -> PatientApi -> EncounterApi -> patient-state
 | + encounter-state gates -> capability gate -> optional provenance -> PROC-%08d
 | -> REQUESTED row -> versioned save -> ProcedureRequested outbox +
 | procedures.requested audit (ADR-137, ADR-146).
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('an active encounter -> a REQUESTED procedure: context copied, PROC-% number, definition snapshot, PHI-free event + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'indication' => 'Infected surgical wound',
        'definition' => ['code' => 'DRESSING-1', 'name' => 'Wound Dressing', 'category' => 'DRESSING_WOUND_CARE'],
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->first());
    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)
        ->and($row->author_user_id)->toBe((string) $user->id)
        ->and($row->requested_by_user_id)->toBe((string) $user->id)
        ->and($row->authored_context)->toBe('CLINICIAN')
        ->and($row->status)->toBe('REQUESTED')
        ->and($row->procedure_number)->toMatch('/^PROC-\d{8}$/')
        ->and((int) $row->definition_version)->toBe(1)
        ->and($row->procedure_category)->toBe('DRESSING_WOUND_CARE')
        ->and($row->indication)->toBe('Infected surgical wound')
        ->and($row->authorized_at)->toBeNull()
        ->and((int) $row->version)->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ProcedureRequested::class)->first());
    expect($outbox->event_name)->toBe('clinical.ProcedureRequested');
    $payload = json_decode((string) $outbox->payload, true, 512, JSON_THROW_ON_ERROR);
    expect(array_keys($payload))->toEqualCanonicalizing([
        'eventId', 'tenantId', 'facilityId', 'patientId', 'encounterId', 'procedureId',
        'definitionId', 'procedureCategory', 'status', 'occurredAt', 'correlationId', 'causationId',
    ]);
    // PHI-free: no procedure_number / indication anywhere in the payload (R-2)
    expect((string) $outbox->payload)->not->toContain('PROC-')
        ->and((string) $outbox->payload)->not->toContain('surgical wound');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'procedures.requested')->first());
    expect($audit->target_type)->toBe('clinical_procedure')
        ->and($audit->target_id)->toBe($id)
        ->and($audit->facility_id)->toBe((string) $facility->id)
        ->and($audit->reason)->toBeNull()
        ->and(json_decode((string) $audit->metadata, true))->toBe([]);
});

test('ARRIVED and COMPLETED encounters both permit requesting; PLANNED / CANCELLED / TRANSFERRED are rejected (ADR-135 parity)', function (EncounterStatus $status, bool $permitted) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus($status)->create(['disposition' => $status === EncounterStatus::Completed ? 'DISCHARGED' : null])->id);

    if ($permitted) {
        $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
        expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->exists()))->toBeTrue();

        return;
    }

    expect(fn () => clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(EncounterNotEligibleForClinicalRecord::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->count()))->toBe(0);
})->with([
    'ARRIVED' => [EncounterStatus::Arrived, true],
    'ACTIVE' => [EncounterStatus::Active, true],
    'COMPLETED' => [EncounterStatus::Completed, true],
    'PLANNED' => [EncounterStatus::Planned, false],
    'CANCELLED' => [EncounterStatus::Cancelled, false],
    'TRANSFERRED' => [EncounterStatus::Transferred, false],
]);

test('requesting is rejected for an INACTIVE / DECEASED / MERGED patient (409)', function (string $patientStatus) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->update(['status' => $patientStatus]));

    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->forPatient($patientId)
        ->withStatus(EncounterStatus::Arrived)->create()->id);

    expect(fn () => clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(PatientNotEligibleForClinicalRecord::class);
})->with(['INACTIVE', 'DECEASED', 'MERGED']);

test('a patient / encounter mismatch -> ProcedureContextMismatch (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [, $encounterA] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $patientB = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    try {
        clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientB, $encounterA, (string) $user->id);
        test()->fail('expected ProcedureContextMismatch');
    } catch (ProcedureContextMismatch $e) {
        expect($e->httpStatus())->toBe(409)->and($e->errorCode())->toBe('procedure_context_mismatch');
    }
});

test('a definition requiring an un-enabled facility capability -> ProcedureCapabilityUnavailable (409; ADR-120)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture(); // capabilities default: ['OUTPATIENT_CARE']
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'definition' => ['requiredCapabilityCode' => 'THEATRE'],
    ]))->toThrow(ProcedureCapabilityUnavailable::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->count()))->toBe(0);
});

test('an enabled facility capability requirement is satisfied', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture(permissions: null, capabilities: ['OUTPATIENT_CARE', 'THEATRE']);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'definition' => ['requiredCapabilityCode' => 'THEATRE'],
    ]);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->exists()))->toBeTrue();
});

test('an optional sourceDiagnosisId is accepted on the SAME encounter, and 409s on a different encounter', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    [$otherPatientId, $otherEncounter] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $diagnosisSameEncounter = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'sourceDiagnosisId' => $diagnosisSameEncounter,
    ]);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->value('source_diagnosis_id')))
        ->toBe($diagnosisSameEncounter);

    $diagnosisOtherEncounter = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $otherPatientId, $otherEncounter, (string) $user->id);
    expect(fn () => clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'sourceDiagnosisId' => $diagnosisOtherEncounter,
    ]))->toThrow(ProcedureContextMismatch::class);

    expect(fn () => clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'sourceDiagnosisId' => (string) Str::uuid7(),
    ]))->toThrow(NotFound::class);
});

test('an optional sourceClinicalPlanId is accepted for the SAME patient regardless of encounter (ClinicalPlan is longitudinal — ADR-146 D-K / M7)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    [$otherPatientId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $planId = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'sourceClinicalPlanId' => $planId,
    ]);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->where('id', $id)->value('source_clinical_plan_id')))
        ->toBe($planId);

    [, $otherEncounterId] = [$otherPatientId, null];
    $otherPlanId = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $otherPatientId, TenantContextScope::run((string) $tenant->id, null, fn () => (string) DB::table('enc_encounters')->where('patient_id', $otherPatientId)->value('id')), (string) $user->id);
    expect(fn () => clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'sourceClinicalPlanId' => $otherPlanId,
    ]))->toThrow(ProcedureContextMismatch::class);
});

// ── authorization ──────────────────────────────────────────────────

test('a caller without clinical.procedures.request is denied (default deny) — no row / event / audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.procedures.read', 'patient.patients.read', 'patient.patients.create',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update',
    ]);
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(AuthorizationException::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->count()))->toBe(0);
});

test('an encounter at a facility outside the actor\'s scope -> NotFound (not a disclosure)', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facilityA->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->forPatient($patientId)
        ->withStatus(EncounterStatus::Arrived)->create()->id);

    expect(fn () => clinicalRequestProcedure((string) $tenant->id, (string) $facilityA->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_procedures')->count()))->toBe(0);
});

test('RequestProcedure rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new RequestProcedure(
        procedureId: 'nope', actorId: (string) Str::uuid7(), patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(), definitionId: (string) Str::uuid7(),
    ))->toThrow(InvalidArgumentException::class);
});
