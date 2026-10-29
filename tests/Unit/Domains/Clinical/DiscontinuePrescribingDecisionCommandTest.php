<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\DiscontinuePrescribingDecision;
use Illuminate\Support\Str;

test('DiscontinuePrescribingDecision valid instantiation and audit entry', function () {
    $cmd = new DiscontinuePrescribingDecision(
        prescribingDecisionId: $id = (string) Str::uuid7(),
        actorId: $actor = (string) Str::uuid7(),
        reason: 'Adverse side effects reported',
    );

    expect($cmd->policyAbility())->toBe('discontinue')
        ->and($cmd->subjectId())->toBe($id);

    $audit = $cmd->auditEntry();
    expect($audit->action)->toBe('clinical.prescribing_decisions.discontinued')
        ->and($audit->targetType)->toBe('prescribing_decision')
        ->and($audit->targetId)->toBe($id)
        ->and($audit->actorId)->toBe($actor)
        ->and($audit->reason)->toBeNull();
});

test('DiscontinuePrescribingDecision requires non-blank reason', function () {
    expect(fn () => new DiscontinuePrescribingDecision(
        prescribingDecisionId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        reason: '   ',
    ))->toThrow(InvalidArgumentException::class, 'reason is required');
});
