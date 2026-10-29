<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetPrescribingDecision;
use App\Domains\Clinical\Contracts\Event\PrescriptionIssued;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PrescribingDecisionContextMismatch;
use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('an active encounter -> an ACTIVE PrescribingDecision: origin context + full dosing intent, PrescriptionIssued + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $decisionId = clinicalRecordPrescribingDecision(
        (string) $tenant->id,
        (string) $facility->id,
        $patientId,
        $encounterId,
        (string) $user->id,
        [
            'medication' => MedicationSubstance::of('Amoxicillin', '500123', 'RxNorm'),
            'dose' => '500 mg',
            'route' => 'ORAL',
            'frequency' => 'TDS',
            'duration' => '7 days',
            'quantity' => '21 capsules',
            'dosingInstruction' => 'Take with meals',
            'therapeuticIntent' => 'Treat acute bacterial infection',
            'indication' => 'Acute maxillary sinusitis',
            'urgency' => 'ROUTINE',
        ],
    );

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_prescribing_decisions')->where('id', $decisionId)->first());
    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)
        ->and($row->author_user_id)->toBe((string) $user->id)
        ->and($row->authored_context)->toBe('CLINICIAN')
        ->and($row->medication_name)->toBe('Amoxicillin')
        ->and($row->medication_code)->toBe('500123')
        ->and($row->medication_code_system)->toBe('RxNorm')
        ->and($row->dose)->toBe('500 mg')
        ->and($row->route)->toBe('ORAL')
        ->and($row->frequency)->toBe('TDS')
        ->and($row->duration)->toBe('7 days')
        ->and($row->quantity)->toBe('21 capsules')
        ->and($row->dosing_instruction)->toBe('Take with meals')
        ->and($row->therapeutic_intent)->toBe('Treat acute bacterial infection')
        ->and($row->indication)->toBe('Acute maxillary sinusitis')
        ->and($row->urgency)->toBe('ROUTINE')
        ->and($row->status)->toBe('ACTIVE')
        ->and($row->discontinued_reason)->toBeNull()
        ->and($row->correction_reason)->toBeNull()
        ->and($row->supersedes_prescribing_decision_id)->toBeNull();

    $event = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PrescriptionIssued::class)
        ->first());

    expect($event)->not->toBeNull();
    $payload = json_decode((string) $event->payload, true);
    expect($payload['prescribingDecisionId'])->toBe($decisionId)
        ->and($payload['medicationCodeSystem'])->toBe('RxNorm')
        ->and($payload['route'])->toBe('ORAL')
        ->and($payload['urgency'])->toBe('ROUTINE')
        ->and($payload['changeType'])->toBeNull()
        // PHI Deny-list checks
        ->and($payload)->not->toHaveKey('medicationName')
        ->and($payload)->not->toHaveKey('medicationCode')
        ->and($payload)->not->toHaveKey('dose')
        ->and($payload)->not->toHaveKey('frequency')
        ->and($payload)->not->toHaveKey('duration')
        ->and($payload)->not->toHaveKey('quantity')
        ->and($payload)->not->toHaveKey('dosingInstruction')
        ->and($payload)->not->toHaveKey('therapeuticIntent')
        ->and($payload)->not->toHaveKey('indication')
        ->and($payload)->not->toHaveKey('quantityValue')  // ADR-183 D-I
        ->and($payload)->not->toHaveKey('quantityUnit');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'clinical.prescribing_decisions.recorded')
        ->where('target_id', $decisionId)
        ->first());

    expect($audit)->not->toBeNull()
        ->and($audit->target_type)->toBe('prescribing_decision');
});

test('patient-state gate: DECEASED, MERGED, INACTIVE patients are rejected (409)', function (string $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')
        ->where('id', $patientId)
        ->update(['status' => $status]));

    clinicalRecordPrescribingDecision((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
})->with(['DECEASED', 'MERGED', 'INACTIVE'])->throws(PatientNotEligibleForClinicalRecord::class);

test('encounter-state gate: PLANNED encounters are rejected (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')
        ->where('id', $encounterId)
        ->update(['status' => EncounterStatus::Planned->value]));

    clinicalRecordPrescribingDecision((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
})->throws(EncounterNotEligibleForClinicalRecord::class);

test('patient / encounter mismatch is rejected (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId1, $encounterId1] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    [$patientId2, $encounterId2] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    clinicalRecordPrescribingDecision((string) $tenant->id, (string) $facility->id, $patientId1, $encounterId2, (string) $user->id);
})->throws(PrescribingDecisionContextMismatch::class);

test('optional ClinicalPlan link must belong to same patient (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patient1, $enc1] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    [$patient2, $enc2] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $planPatient2 = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patient2, $enc2, (string) $user->id);

    expect(fn () => clinicalRecordPrescribingDecision((string) $tenant->id, (string) $facility->id, $patient1, $enc1, (string) $user->id, [
        'clinicalPlanId' => $planPatient2,
    ]))->toThrow(PrescribingDecisionContextMismatch::class);
});

test('per-encounter-facility refinement: missing permission at encounter facility yields NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->forPatient($patientId)
        ->withStatus(EncounterStatus::Active)->create()->id);

    clinicalRecordPrescribingDecision((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
})->throws(NotFound::class);

test('unauthorized actor is rejected by bus policy', function () {
    $fixture = clinicalFixture(); // lacks clinical.prescribing.record
    [$patient, $encounter] = clinicalPatientWithEncounter((string) $fixture['tenant']->id, (string) $fixture['facility']->id, (string) $fixture['user']->id);

    clinicalRecordPrescribingDecision((string) $fixture['tenant']->id, (string) $fixture['facility']->id, $patient, $encounter, (string) $fixture['user']->id);
})->throws(AuthorizationException::class);

test('ADR-183 C.10: a prescriber-authored structured quantity persists, nulls the free-text quantity, and never rides PrescriptionIssued', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $decisionId = clinicalRecordPrescribingDecision(
        (string) $tenant->id,
        (string) $facility->id,
        $patientId,
        $encounterId,
        (string) $user->id,
        ['quantityValue' => '21', 'quantityUnit' => 'capsule'],
    );

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_prescribing_decisions')->where('id', $decisionId)->first());
    expect($row->quantity)->toBeNull()
        ->and($row->quantity_value)->toBe('21.0000')
        ->and($row->quantity_unit)->toBe('capsule');

    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)
        ->dispatch(new GetPrescribingDecision($decisionId)));
    expect($summary->quantityValue)->toBe('21.0000')
        ->and($summary->quantityUnit)->toBe('capsule')
        ->and($summary->quantity)->toBeNull();

    $event = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PrescriptionIssued::class)->first());
    $payload = json_decode((string) $event->payload, true);
    expect($payload)->not->toHaveKey('quantityValue')
        ->and($payload)->not->toHaveKey('quantityUnit');
});
