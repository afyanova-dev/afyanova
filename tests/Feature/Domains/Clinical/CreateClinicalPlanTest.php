<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\ClinicalPlanCreated;
use App\Domains\Clinical\Domain\Exception\ClinicalPlanContextMismatch;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanType;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';
require_once __DIR__.'/../../../Support/problem.php';

test('an active encounter -> an ACTIVE ClinicalPlan: origin context, facets, ClinicalPlanCreated outbox event + audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $planId = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'planType' => ClinicalPlanType::GENERAL,
        'clinicalIntent' => 'Manage acute bronchitis',
        'goals' => 'Cough resolution',
        'plannedActions' => 'Prescribe inhaler',
        'instructions' => 'Drink plenty of water',
        'followUp' => 'Review in 7 days',
    ]);

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_plans')->where('id', $planId)->first());
    expect($row)->not->toBeNull()
        ->and($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)
        ->and($row->author_user_id)->toBe((string) $user->id)
        ->and($row->authored_context)->toBe('CLINICIAN')
        ->and($row->plan_type)->toBe('GENERAL')
        ->and($row->clinical_intent)->toBe('Manage acute bronchitis')
        ->and($row->goals)->toBe('Cough resolution')
        ->and($row->planned_actions)->toBe('Prescribe inhaler')
        ->and($row->instructions)->toBe('Drink plenty of water')
        ->and($row->follow_up)->toBe('Review in 7 days')
        ->and($row->status)->toBe('ACTIVE')
        ->and($row->closed_reason)->toBeNull()
        ->and((int) $row->version)->toBe(0);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ClinicalPlanCreated::class)
        ->first());

    expect($outbox)->not->toBeNull();
    $payload = json_decode((string) $outbox->payload, true);
    expect($payload['clinicalPlanId'])->toBe($planId)
        ->and($payload['planType'])->toBe('GENERAL')
        ->and($payload)->not->toHaveKey('clinicalIntent')
        ->and($payload)->not->toHaveKey('goals');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'clinical.plans.created')
        ->where('target_id', $planId)
        ->first());

    expect($audit)->not->toBeNull()
        ->and($audit->target_type)->toBe('clinical_plan');
});

test('patient-state gate: DECEASED, MERGED, INACTIVE patients are rejected (409)', function (string $status) {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')
        ->where('id', $patientId)
        ->update(['status' => $status]));

    clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
})->with(['DECEASED', 'MERGED', 'INACTIVE'])->throws(PatientNotEligibleForClinicalRecord::class);

test('encounter-state gate: PLANNED encounters are rejected (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')
        ->where('id', $encounterId)
        ->update(['status' => EncounterStatus::Planned->value]));

    clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
})->throws(EncounterNotEligibleForClinicalRecord::class);

test('patient / encounter mismatch is rejected (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId1, $encounterId1] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    [$patientId2, $encounterId2] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId1, $encounterId2, (string) $user->id);
})->throws(ClinicalPlanContextMismatch::class);

test('provenance: indication diagnosis and problem must belong to same patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    grantPermissionsToRole((string) $tenant->id, $user->id, ['clinical.diagnoses.record', 'clinical.problems.record']);

    [$patient1, $enc1] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    [$patient2, $enc2] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $diagPatient2 = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patient2, $enc2, (string) $user->id);

    expect(fn () => clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patient1, $enc1, (string) $user->id, [
        'indicationDiagnosisId' => $diagPatient2,
    ]))->toThrow(ClinicalPlanContextMismatch::class);
});

test('per-encounter-facility refinement: missing permission at encounter facility yields NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->forPatient($patientId)
        ->withStatus(EncounterStatus::Active)->create()->id);

    clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
})->throws(NotFound::class);

test('unauthorized actor is rejected by bus policy', function () {
    $fixture = clinicalFixture(); // lacks clinical.plans.record
    [$patient, $encounter] = clinicalPatientWithEncounter((string) $fixture['tenant']->id, (string) $fixture['facility']->id, (string) $fixture['user']->id);

    clinicalCreatePlan((string) $fixture['tenant']->id, (string) $fixture['facility']->id, $patient, $encounter, (string) $fixture['user']->id);
})->throws(AuthorizationException::class);
