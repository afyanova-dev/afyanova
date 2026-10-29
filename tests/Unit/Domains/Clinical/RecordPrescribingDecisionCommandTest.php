<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RecordPrescribingDecision;
use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('RecordPrescribingDecision valid instantiation and audit entry', function () {
    $cmd = new RecordPrescribingDecision(
        prescribingDecisionId: $id = (string) Str::uuid7(),
        actorId: $actor = (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        dose: '500 mg',
    );

    expect($cmd->policyAbility())->toBe('record')
        ->and($cmd->subjectId())->toBeNull();

    $audit = $cmd->auditEntry();
    expect($audit->action)->toBe('clinical.prescribing_decisions.recorded')
        ->and($audit->targetType)->toBe('prescribing_decision')
        ->and($audit->targetId)->toBe($id)
        ->and($audit->actorId)->toBe($actor);
});

test('RecordPrescribingDecision rejects future occurredAt and requires reason for backdate beyond 24h', function () {
    expect(fn () => new RecordPrescribingDecision(
        prescribingDecisionId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        occurredAt: CarbonImmutable::now()->addHour()->toIso8601String(),
    ))->toThrow(InvalidArgumentException::class, 'must not be in the future');

    expect(fn () => new RecordPrescribingDecision(
        prescribingDecisionId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        occurredAt: CarbonImmutable::now()->subHours(25)->toIso8601String(),
        reason: null,
    ))->toThrow(InvalidArgumentException::class, 'reason is required');
});

test('ADR-183 D-B/D-E: RecordPrescribingDecision accepts a structured quantity alone and enforces the required-iff-present rule', function () {
    $cmd = new RecordPrescribingDecision(
        prescribingDecisionId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        quantityValue: '2.5',
        quantityUnit: 'mL',
    );
    expect($cmd->quantityValue)->toBe('2.5')->and($cmd->quantityUnit)->toBe('mL')->and($cmd->quantity)->toBeNull();

    // both free-text + structured
    expect(fn () => new RecordPrescribingDecision(
        prescribingDecisionId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        quantity: '21 capsules',
        quantityValue: '21',
        quantityUnit: 'capsule',
    ))->toThrow(InvalidArgumentException::class, 'mutually exclusive');

    // value without unit
    expect(fn () => new RecordPrescribingDecision(
        prescribingDecisionId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        quantityValue: '21',
    ))->toThrow(InvalidArgumentException::class, '$quantityUnit is required');

    // unit without value
    expect(fn () => new RecordPrescribingDecision(
        prescribingDecisionId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        quantityUnit: 'capsule',
    ))->toThrow(InvalidArgumentException::class, 'must be absent');

    // non-positive value
    expect(fn () => new RecordPrescribingDecision(
        prescribingDecisionId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        quantityValue: '-1',
        quantityUnit: 'capsule',
    ))->toThrow(InvalidArgumentException::class, 'positive numeric string');
});
