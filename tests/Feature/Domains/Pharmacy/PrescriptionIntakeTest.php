<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectPrescribingDecision;
use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;
use App\Domains\Pharmacy\Application\Command\IngestPrescription;
use App\Domains\Pharmacy\Application\Listener\OnPrescriptionIssued;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Shared\Bus\CommandBus;
use App\Shared\Events\DispatchListenerJob;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, a: string, patient: string, encounter: string}
 */
function intakeSetup(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = pharmacyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    [$patientId, $encounterId] = clinicalPatientWithEncounter($t, $f, $a);

    return ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patientId, 'encounter' => $encounterId];
}

test('OnPrescriptionIssued creates a RECEIVED stub carrying only PHI-minimal coded fields — no medication name / dose / quantity', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'encounter' => $encounter] = intakeSetup();

    ['prescribingDecisionId' => $decisionId, 'prescriptionId' => $rxId] =
        pharmacyIssuePrescription($t, $f, $patient, $encounter, $a);

    $rx = rxPrescription($t, $rxId);

    expect($rx->status)->toBe(Prescription::STATUS_RECEIVED)
        ->and((string) $rx->prescribing_decision_id)->toBe($decisionId)
        ->and((string) $rx->patient_id)->toBe($patient)
        ->and((string) $rx->facility_id)->toBe($f)
        ->and($rx->prescription_number)->toStartWith('RX-')
        ->and($rx->medication_code_system)->toBe('RxNorm')  // coded — from the event
        ->and($rx->route)->toBe('ORAL')
        ->and($rx->urgency)->toBe('ROUTINE')
        // the clinical snapshot is NOT fetched at intake (ADR-181 C1)
        ->and($rx->medication_name)->toBeNull()
        ->and($rx->dose)->toBeNull()
        ->and($rx->frequency)->toBeNull()
        ->and($rx->prescribed_quantity)->toBeNull()
        ->and($rx->dosing_instruction)->toBeNull()
        ->and($rx->accepted_by_user_id)->toBeNull()
        ->and((int) $rx->version)->toBe(0);
});

test('intake is idempotent — a re-delivered event does not create a second prescription', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'encounter' => $encounter] = intakeSetup();

    ['prescribingDecisionId' => $decisionId, 'job' => $job] =
        pharmacyIssuePrescription($t, $f, $patient, $encounter, $a);

    $job->handle(); // redelivery
    $job->handle();

    $count = TenantContextScope::run($t, null, fn () => Prescription::query()
        ->where('prescribing_decision_id', $decisionId)->count());
    expect($count)->toBe(1);

    // idempotency row keyed on IngestPrescription + the prescribing_decision_id
    test()->assertDatabaseHas('shared_idempotency_keys', [
        'tenant_id' => $t, 'scope' => IngestPrescription::class, 'key' => $decisionId, 'status' => 'completed',
    ]);
});

test('a CORRECTION PrescriptionIssued creates a NEW independent RECEIVED stub (no supersession in Pharm.1)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'encounter' => $encounter] = intakeSetup();

    ['prescribingDecisionId' => $originalId] = pharmacyIssuePrescription($t, $f, $patient, $encounter, $a);

    // Clinical corrects the decision → a NEW decision id + a CORRECTION event.
    $correctedId = (string) Str::uuid7();
    Queue::fake([DispatchListenerJob::class]);

    TenantContextScope::run($t, $f, fn () => app(CommandBus::class)->dispatch(new CorrectPrescribingDecision(
        newPrescribingDecisionId: $correctedId,
        supersedesPrescribingDecisionId: $originalId,
        actorId: $a,
        medication: MedicationSubstance::of('Amoxicillin', '500123', 'RxNorm'),
        reason: 'Dose correction — 250 mg not 500 mg',
        dose: '250 mg',
        route: 'ORAL',
        frequency: 'TDS',
        duration: '7 days',
        quantity: '21 capsules',
        dosingInstruction: 'Take with food',
        urgency: 'ROUTINE',
    )));

    test()->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
    foreach (Queue::pushed(DispatchListenerJob::class) as $job) {
        if ($job->listenerClass === OnPrescriptionIssued::class) {
            $job->handle();
        }
    }

    $rows = TenantContextScope::run($t, null, fn () => Prescription::query()
        ->where('patient_id', $patient)->orderBy('recorded_at')->get()->values());

    expect($rows)->toHaveCount(2)
        ->and((string) $rows[1]->prescribing_decision_id)->toBe($correctedId)
        ->and($rows[1]->change_type)->toBe('CORRECTION')
        ->and($rows[1]->status)->toBe(Prescription::STATUS_RECEIVED)
        // no supersession link on the original stub
        ->and((string) $rows[0]->prescribing_decision_id)->toBe($originalId)
        ->and($rows[0]->status)->toBe(Prescription::STATUS_RECEIVED);
});

test('IngestPrescription rejects a malformed id / an illegal changeType (IC §3)', function () {
    expect(fn () => new IngestPrescription(
        prescriptionId: 'not-a-uuid', prescribingDecisionId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(), encounterId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(), authorUserId: (string) Str::uuid7(),
        medicationCodeSystem: null, route: null, urgency: null, changeType: null,
        occurredAt: now()->toIso8601String(),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new IngestPrescription(
        prescriptionId: (string) Str::uuid7(), prescribingDecisionId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(), encounterId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(), authorUserId: (string) Str::uuid7(),
        medicationCodeSystem: null, route: null, urgency: null, changeType: 'REVISION',
        occurredAt: now()->toIso8601String(),
    ))->toThrow(InvalidArgumentException::class);
});

test('rx_prescriptions is tenant-isolated (FORCE RLS)', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'rx_prescriptions')->pluck('policyname')->all();
    expect($policies)->toContain('tenant_isolation');

    $forced = DB::selectOne("select relforcerowsecurity as f from pg_class where relname = 'rx_prescriptions'");
    expect($forced->f)->toBeTrue();
});
