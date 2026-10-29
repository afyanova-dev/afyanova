<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\UpdateClinicalPlan;
use Illuminate\Support\Str;

test('UpdateClinicalPlan valid instantiation and audit entry', function () {
    $cmd = new UpdateClinicalPlan(
        clinicalPlanId: $id = (string) Str::uuid7(),
        actorId: $actor = (string) Str::uuid7(),
        changeType: 'REVISION',
        planType: 'MEDICATION',
        clinicalIntent: 'Revised intent',
    );

    expect($cmd->policyAbility())->toBe('update')
        ->and($cmd->subjectId())->toBe($id);

    $audit = $cmd->auditEntry();
    expect($audit->action)->toBe('clinical.plans.updated')
        ->and($audit->targetType)->toBe('clinical_plan')
        ->and($audit->targetId)->toBe($id)
        ->and($audit->actorId)->toBe($actor)
        ->and($audit->reason)->toBeNull();
});

test('UpdateClinicalPlan requires reason for CORRECTION', function () {
    expect(fn () => new UpdateClinicalPlan(
        clinicalPlanId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        changeType: 'CORRECTION',
        planType: 'MEDICATION',
        clinicalIntent: 'Corrected intent',
        reason: null,
    ))->toThrow(InvalidArgumentException::class, 'reason is required when changeType is CORRECTION');
});
