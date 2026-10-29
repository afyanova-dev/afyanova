<?php

declare(strict_types=1);

use App\Domains\Pharmacy\Application\Command\AcceptPrescription;
use App\Domains\Pharmacy\Application\Command\CancelPrescription;
use App\Domains\Pharmacy\Application\Query\GetPatientPrescriptions;
use App\Domains\Pharmacy\Application\Query\GetPrescription;
use App\Domains\Pharmacy\Application\Query\SearchPrescriptions;
use App\Domains\Pharmacy\Contracts\Event\PrescriptionAccepted;
use App\Domains\Pharmacy\Contracts\Event\PrescriptionCancelled;
use App\Domains\Pharmacy\Domain\Exception\IllegalPrescriptionStatusTransition;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, a: string, patient: string, encounter: string, rx: string, decision: string, role_id: string}
 */
function acceptedSetup(bool $accept = false): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = pharmacyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    [$patientId, $encounterId] = clinicalPatientWithEncounter($t, $f, $a);
    ['prescribingDecisionId' => $decisionId, 'prescriptionId' => $rxId] =
        pharmacyIssuePrescription($t, $f, $patientId, $encounterId, $a);

    if ($accept) {
        dispatchRx($t, $f, new AcceptPrescription(prescriptionId: $rxId, actorId: $a));
    }

    return [
        't' => $t, 'f' => $f, 'a' => $a, 'patient' => $patientId, 'encounter' => $encounterId,
        'rx' => $rxId, 'decision' => $decisionId, 'role_id' => $roleId,
    ];
}

test('AcceptPrescription fetches the decision under the pharmacist auth, writes the immutable snapshot, moves RECEIVED → ACTIVE and emits PrescriptionAccepted', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'rx' => $rx] = acceptedSetup();

    dispatchRx($t, $f, new AcceptPrescription(prescriptionId: $rx, actorId: $a));

    $p = rxPrescription($t, $rx);
    expect($p->status)->toBe(Prescription::STATUS_ACTIVE)
        ->and((string) $p->accepted_by_user_id)->toBe($a)
        ->and($p->medication_name)->toBe('Amoxicillin')
        ->and($p->dose)->toBe('500 mg')
        ->and($p->frequency)->toBe('TDS')
        ->and($p->prescribed_quantity)->toBe('21 capsules')
        ->and($p->dosing_instruction)->toBe('Take with food')
        ->and((int) $p->version)->toBeGreaterThan(0);

    $summary = queryRx($t, $f, new GetPrescription($rx));
    expect($summary->status)->toBe(Prescription::STATUS_ACTIVE)
        ->and($summary->medicationName)->toBe('Amoxicillin');

    // event is PHI-free — no medication name / dose on the wire
    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PrescriptionAccepted::class)->first());
    expect($outbox->payload)->toContain('RxNorm')->toContain('ORAL')
        ->and($outbox->payload)->not->toContain('Amoxicillin')
        ->and($outbox->payload)->not->toContain('500 mg');
});

test('a second AcceptPrescription is rejected (RECEIVED-only transition)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'rx' => $rx] = acceptedSetup(accept: true);

    expect(fn () => dispatchRx($t, $f, new AcceptPrescription(prescriptionId: $rx, actorId: $a)))
        ->toThrow(IllegalPrescriptionStatusTransition::class);
});

test('CancelPrescription is administrative: RECEIVED | ACTIVE → CANCELLED, reason ROW-ONLY, emits PrescriptionCancelled', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'rx' => $rx] = acceptedSetup(accept: true);

    dispatchRx($t, $f, new CancelPrescription(prescriptionId: $rx, actorId: $a, reason: 'Patient never collected'));

    $p = rxPrescription($t, $rx);
    expect($p->status)->toBe(Prescription::STATUS_CANCELLED)
        ->and((string) $p->cancelled_by_user_id)->toBe($a)
        ->and($p->cancel_reason)->toBe('Patient never collected');

    // audit row carries the free-text reason; the event carries only the coded value
    test()->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $t, 'action' => 'pharmacy.prescription_cancelled', 'reason' => 'Patient never collected',
    ]);
    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PrescriptionCancelled::class)->first());
    expect($outbox->payload)->toContain('CANCELLED')
        ->and($outbox->payload)->not->toContain('never collected');

    // CANCELLED is terminal
    expect(fn () => dispatchRx($t, $f, new CancelPrescription(prescriptionId: $rx, actorId: $a, reason: 'again')))
        ->toThrow(IllegalPrescriptionStatusTransition::class);
});

test('AcceptPrescription is denied to an actor without pharmacy.prescriptions.accept', function () {
    ['t' => $t, 'f' => $f, 'rx' => $rx] = acceptedSetup();

    $stranger = pharmacyUserWithKeys($t, $f, ['pharmacy.access', 'clinical.prescribing.read']);
    test()->actingAs($stranger);

    expect(fn () => dispatchRx($t, $f, new AcceptPrescription(prescriptionId: $rx, actorId: (string) $stranger->id)))
        ->toThrow(AuthorizationException::class);
});

test('SearchPrescriptions is the facility queue; GetPatientPrescriptions filters by status; RLS isolates the tenant', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'patient' => $patient, 'encounter' => $encounter, 'rx' => $rx] = acceptedSetup();

    // a second prescribing decision on the same encounter → a second prescription, accepted
    ['prescriptionId' => $rx2] = pharmacyIssuePrescription($t, $f, $patient, $encounter, $a);
    dispatchRx($t, $f, new AcceptPrescription(prescriptionId: $rx2, actorId: $a));

    expect(queryRx($t, $f, new SearchPrescriptions(facilityId: $f)))->toHaveCount(2)
        ->and(queryRx($t, $f, new SearchPrescriptions(facilityId: $f, status: 'RECEIVED')))->toHaveCount(1)
        ->and(queryRx($t, $f, new GetPatientPrescriptions(patientId: $patient)))->toHaveCount(2)
        ->and(queryRx($t, $f, new GetPatientPrescriptions(patientId: $patient, status: 'ACTIVE')))->toHaveCount(1);

    // another tenant sees nothing at this facility
    ['tenant' => $other, 'facility' => $otherF, 'user' => $otherU] = pharmacyFixture();
    test()->actingAs($otherU);
    expect(fn () => queryRx((string) $other->id, (string) $otherF->id, new SearchPrescriptions(facilityId: $f)))
        ->toThrow(NotFound::class);
});
