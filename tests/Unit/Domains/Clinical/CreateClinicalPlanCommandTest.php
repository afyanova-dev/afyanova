<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CreateClinicalPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('CreateClinicalPlan valid instantiation and audit entry', function () {
    $cmd = new CreateClinicalPlan(
        clinicalPlanId: $id = (string) Str::uuid7(),
        actorId: $actor = (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        planType: 'GENERAL',
        clinicalIntent: 'Intent description',
    );

    expect($cmd->policyAbility())->toBe('record')
        ->and($cmd->subjectId())->toBeNull();

    $audit = $cmd->auditEntry();
    expect($audit->action)->toBe('clinical.plans.created')
        ->and($audit->targetType)->toBe('clinical_plan')
        ->and($audit->targetId)->toBe($id)
        ->and($audit->actorId)->toBe($actor);
});

test('CreateClinicalPlan rejects invalid UUID or blank clinicalIntent', function () {
    expect(fn () => new CreateClinicalPlan(
        clinicalPlanId: 'not-a-uuid',
        actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        planType: 'GENERAL',
        clinicalIntent: 'Intent',
    ))->toThrow(InvalidArgumentException::class, 'must be a UUID');

    expect(fn () => new CreateClinicalPlan(
        clinicalPlanId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        planType: 'GENERAL',
        clinicalIntent: '   ',
    ))->toThrow(InvalidArgumentException::class, 'must not be blank');
});

test('CreateClinicalPlan rejects future occurredAt and requires backdate reason beyond 24h', function () {
    expect(fn () => new CreateClinicalPlan(
        clinicalPlanId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        planType: 'GENERAL',
        clinicalIntent: 'Intent',
        occurredAt: CarbonImmutable::now()->addHour()->toIso8601String(),
    ))->toThrow(InvalidArgumentException::class, 'must not be in the future');

    expect(fn () => new CreateClinicalPlan(
        clinicalPlanId: (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        planType: 'GENERAL',
        clinicalIntent: 'Intent',
        occurredAt: CarbonImmutable::now()->subHours(25)->toIso8601String(),
        reason: null,
    ))->toThrow(InvalidArgumentException::class, 'reason is required');
});
