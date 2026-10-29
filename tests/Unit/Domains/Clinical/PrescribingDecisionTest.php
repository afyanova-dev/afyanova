<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\PrescriptionIssued;
use App\Domains\Clinical\Domain\Exception\IllegalPrescribingDecisionStatusTransition;
use App\Domains\Clinical\Domain\Exception\PrescribingDecisionAlreadySuperseded;
use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;
use App\Domains\Clinical\Domain\ValueObject\PrescribingDecisionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function makeDecision(
    ?MedicationSubstance $med = null,
    ?string $quantity = '21 capsules',
    ?string $quantityValue = null,
    ?string $quantityUnit = null,
): PrescribingDecision {
    return PrescribingDecision::record(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        medication: $med ?? MedicationSubstance::of('Amoxicillin', '500123', 'RxNorm'),
        dose: '500 mg',
        route: 'ORAL',
        frequency: 'TDS',
        duration: '7 days',
        quantity: $quantity,
        quantityValue: $quantityValue,
        quantityUnit: $quantityUnit,
        dosingInstruction: 'Take with food',
        therapeuticIntent: 'Eradication of bacterial infection',
        indication: 'Acute bacterial sinusitis',
        urgency: 'ROUTINE',
        clinicalPlanId: null,
        indicationDiagnosisId: null,
        indicationProblemId: null,
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );
}

test('record() produces a version-0 ACTIVE decision with CLINICIAN context, records PrescriptionIssued', function () {
    $dec = makeDecision();

    expect((int) $dec->version)->toBe(0)
        ->and($dec->status)->toBe(PrescribingDecisionStatus::ACTIVE)
        ->and($dec->authored_context)->toBe('CLINICIAN')
        ->and($dec->medication_name)->toBe('Amoxicillin')
        ->and($dec->medication_code)->toBe('500123')
        ->and($dec->medication_code_system)->toBe('RxNorm')
        ->and($dec->dose)->toBe('500 mg')
        ->and($dec->route)->toBe('ORAL')
        ->and($dec->frequency)->toBe('TDS')
        ->and($dec->duration)->toBe('7 days')
        ->and($dec->quantity)->toBe('21 capsules')
        ->and($dec->dosing_instruction)->toBe('Take with food')
        ->and($dec->therapeutic_intent)->toBe('Eradication of bacterial infection')
        ->and($dec->indication)->toBe('Acute bacterial sinusitis')
        ->and($dec->urgency)->toBe('ROUTINE')
        ->and($dec->isActive())->toBeTrue()
        ->and($dec->isSuperseded())->toBeFalse()
        ->and($dec->isDiscontinued())->toBeFalse()
        ->and($dec->supersedes_prescribing_decision_id)->toBeNull();

    $events = $dec->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PrescriptionIssued::class)
        ->and($events[0]->prescribingDecisionId)->toBe((string) $dec->id)
        ->and($events[0]->changeType)->toBeNull()
        ->and($events[0]->medicationCodeSystem)->toBe('RxNorm')
        ->and($events[0]->route)->toBe('ORAL');
});

test('correcting() creates a new superseding decision and markSupersededBy stamps original', function () {
    $original = makeDecision();
    $author = (string) Str::uuid7();
    $newId = (string) Str::uuid7();

    $correction = PrescribingDecision::correcting(
        id: $newId,
        original: $original,
        authorUserId: $author,
        medication: MedicationSubstance::of('Amoxicillin', '500123', 'RxNorm'),
        reason: 'Increased dose for severe infection',
        dose: '1000 mg',
        route: 'ORAL',
        frequency: 'TDS',
        duration: '7 days',
        quantity: '21 tablets',
        quantityValue: null,
        quantityUnit: null,
        dosingInstruction: 'Take with food',
        therapeuticIntent: 'Eradication of bacterial infection',
        indication: 'Severe sinusitis',
        urgency: 'URGENT',
        clinicalPlanId: null,
        indicationDiagnosisId: null,
        indicationProblemId: null,
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );

    expect($correction->status)->toBe(PrescribingDecisionStatus::ACTIVE)
        ->and($correction->supersedes_prescribing_decision_id)->toBe((string) $original->id)
        ->and($correction->correction_reason)->toBe('Increased dose for severe infection')
        ->and($correction->dose)->toBe('1000 mg');

    $events = $correction->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PrescriptionIssued::class)
        ->and($events[0]->changeType)->toBe('CORRECTION');

    $original->markSupersededBy($newId);
    expect($original->status)->toBe(PrescribingDecisionStatus::SUPERSEDED)
        ->and($original->isSuperseded())->toBeTrue()
        ->and($original->superseded_by_prescribing_decision_id)->toBe($newId)
        ->and($original->superseded_at)->not->toBeNull();

    expect(fn () => $original->markSupersededBy((string) Str::uuid7()))
        ->toThrow(PrescribingDecisionAlreadySuperseded::class);
});

test('ADR-183 D-B/D-N: record() stores a structured quantity immutably and correcting() carries it onto the superseding row', function () {
    $original = makeDecision(quantity: null, quantityValue: '21', quantityUnit: 'capsule');

    expect($original->quantity)->toBeNull()
        ->and($original->quantity_value)->toBe('21.0000')
        ->and($original->quantity_unit)->toBe('capsule');

    // ADR-183 D-N(c): a correction that changes ONLY the structured quantity.
    $correction = PrescribingDecision::correcting(
        id: (string) Str::uuid7(),
        original: $original,
        authorUserId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin', '500123', 'RxNorm'),
        reason: 'Recorded quantity was wrong — should have been 30',
        dose: '500 mg',
        route: 'ORAL',
        frequency: 'TDS',
        duration: '7 days',
        quantity: null,
        quantityValue: '30',
        quantityUnit: 'capsule',
        dosingInstruction: 'Take with food',
        therapeuticIntent: 'Eradication of bacterial infection',
        indication: 'Acute bacterial sinusitis',
        urgency: 'ROUTINE',
        clinicalPlanId: null,
        indicationDiagnosisId: null,
        indicationProblemId: null,
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );

    expect($correction->quantity)->toBeNull()
        ->and($correction->quantity_value)->toBe('30.0000')
        ->and($correction->quantity_unit)->toBe('capsule')
        ->and($correction->dose)->toBe('500 mg')
        ->and($correction->duration)->toBe('7 days')
        ->and($correction->supersedes_prescribing_decision_id)->toBe((string) $original->id)
        // the original row is untouched by correcting() (ADR-143 D-J)
        ->and($original->quantity_value)->toBe('21.0000');
});

test('discontinue() updates status to DISCONTINUED with reason or rejects if not active', function () {
    $dec = makeDecision();
    $dec->discontinue('Patient developed rash');

    expect($dec->status)->toBe(PrescribingDecisionStatus::DISCONTINUED)
        ->and($dec->isDiscontinued())->toBeTrue()
        ->and($dec->discontinued_reason)->toBe('Patient developed rash');

    expect(fn () => $dec->discontinue('Another reason'))
        ->toThrow(IllegalPrescribingDecisionStatusTransition::class);

    expect(fn () => makeDecision()->discontinue('   '))
        ->toThrow(InvalidArgumentException::class, 'reason is required');
});
