<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectPrescribingDecision;
use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;
use Illuminate\Support\Str;

test('CorrectPrescribingDecision valid instantiation and audit entry', function () {
    $cmd = new CorrectPrescribingDecision(
        newPrescribingDecisionId: $newId = (string) Str::uuid7(),
        supersedesPrescribingDecisionId: $origId = (string) Str::uuid7(),
        actorId: $actor = (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        reason: 'Adjusting strength',
    );

    expect($cmd->policyAbility())->toBe('correct')
        ->and($cmd->subjectId())->toBe($origId);

    $audit = $cmd->auditEntry();
    expect($audit->action)->toBe('clinical.prescribing_decisions.corrected')
        ->and($audit->targetType)->toBe('prescribing_decision')
        ->and($audit->targetId)->toBe($newId)
        ->and($audit->actorId)->toBe($actor)
        ->and($audit->reason)->toBeNull();
});

test('CorrectPrescribingDecision rejects self-supersession and blank reason', function () {
    $id = (string) Str::uuid7();

    expect(fn () => new CorrectPrescribingDecision(
        newPrescribingDecisionId: $id,
        supersedesPrescribingDecisionId: $id,
        actorId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        reason: 'Reason',
    ))->toThrow(InvalidArgumentException::class, 'cannot supersede itself');

    expect(fn () => new CorrectPrescribingDecision(
        newPrescribingDecisionId: (string) Str::uuid7(),
        supersedesPrescribingDecisionId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        reason: '   ',
    ))->toThrow(InvalidArgumentException::class, 'reason is required');
});

test('ADR-183 D-B/D-E: CorrectPrescribingDecision carries a structured quantity and enforces the same rules as record', function () {
    $cmd = new CorrectPrescribingDecision(
        newPrescribingDecisionId: (string) Str::uuid7(),
        supersedesPrescribingDecisionId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        reason: 'Wrong quantity recorded',
        quantityValue: '30',
        quantityUnit: 'capsule',
    );
    expect($cmd->quantityValue)->toBe('30')->and($cmd->quantityUnit)->toBe('capsule');

    expect(fn () => new CorrectPrescribingDecision(
        newPrescribingDecisionId: (string) Str::uuid7(),
        supersedesPrescribingDecisionId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        medication: MedicationSubstance::of('Amoxicillin'),
        reason: 'Wrong quantity recorded',
        quantity: '30 capsules',
        quantityValue: '30',
        quantityUnit: 'capsule',
    ))->toThrow(InvalidArgumentException::class, 'mutually exclusive');
});
