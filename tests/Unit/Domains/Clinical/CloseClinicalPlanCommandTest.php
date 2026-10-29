<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CloseClinicalPlan;
use Illuminate\Support\Str;

test('CloseClinicalPlan valid instantiation for COMPLETED and DISCONTINUED', function () {
    $cmdCompleted = new CloseClinicalPlan(
        clinicalPlanId: $id = (string) Str::uuid7(),
        actorId: $actor = (string) Str::uuid7(),
        outcome: 'COMPLETED',
    );

    expect($cmdCompleted->policyAbility())->toBe('close')
        ->and($cmdCompleted->subjectId())->toBe($id);

    $cmdDiscontinued = new CloseClinicalPlan(
        clinicalPlanId: $id,
        actorId: $actor,
        outcome: 'DISCONTINUED',
        reason: 'Treatment no longer needed',
    );

    expect($cmdDiscontinued->policyAbility())->toBe('close');
});

test('CloseClinicalPlan enforces reason rules based on outcome', function () {
    expect(fn () => new CloseClinicalPlan(
        clinicalPlanId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        outcome: 'DISCONTINUED',
        reason: null,
    ))->toThrow(InvalidArgumentException::class, 'reason is required when outcome is DISCONTINUED');

    expect(fn () => new CloseClinicalPlan(
        clinicalPlanId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        outcome: 'COMPLETED',
        reason: 'Some reason',
    ))->toThrow(InvalidArgumentException::class, 'reason is forbidden when outcome is COMPLETED');
});
