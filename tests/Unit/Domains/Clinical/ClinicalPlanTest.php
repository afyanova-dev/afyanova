<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\ClinicalPlanCreated;
use App\Domains\Clinical\Contracts\Event\ClinicalPlanUpdated;
use App\Domains\Clinical\Domain\Exception\IllegalClinicalPlanStatusTransition;
use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanChangeType;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanStatus;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function makePlan(?string $type = null, ?string $intent = null): ClinicalPlan
{
    return ClinicalPlan::create(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        authorUserId: (string) Str::uuid7(),
        planType: $type ?? ClinicalPlanType::GENERAL,
        clinicalIntent: $intent ?? 'Standard treatment intent',
        goals: 'Reduce inflammation',
        plannedActions: 'Rest and follow-up',
        instructions: 'Drink water',
        followUp: '2 weeks',
        indicationDiagnosisId: null,
        indicationProblemId: null,
        occurredAt: CarbonImmutable::now(),
        recordedAt: CarbonImmutable::now(),
    );
}

test('create() produces a version-0 ACTIVE ClinicalPlan with CLINICIAN context, records ClinicalPlanCreated', function () {
    $plan = makePlan();

    expect((int) $plan->version)->toBe(0)
        ->and($plan->status)->toBe(ClinicalPlanStatus::ACTIVE)
        ->and($plan->authored_context)->toBe('CLINICIAN')
        ->and($plan->plan_type)->toBe('GENERAL')
        ->and($plan->clinical_intent)->toBe('Standard treatment intent')
        ->and($plan->goals)->toBe('Reduce inflammation')
        ->and($plan->planned_actions)->toBe('Rest and follow-up')
        ->and($plan->instructions)->toBe('Drink water')
        ->and($plan->follow_up)->toBe('2 weeks')
        ->and($plan->closed_reason)->toBeNull()
        ->and($plan->isActive())->toBeTrue()
        ->and($plan->isCompleted())->toBeFalse()
        ->and($plan->isDiscontinued())->toBeFalse()
        ->and($plan->releasePendingHistory())->toBe([]);

    $events = $plan->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ClinicalPlanCreated::class)
        ->and($events[0]->clinicalPlanId)->toBe((string) $plan->id)
        ->and($events[0]->planType)->toBe('GENERAL');
});

test('revise() with REVISION adds history snapshot and emits ClinicalPlanUpdated', function () {
    $plan = makePlan();
    $plan->releaseEvents();

    $plan->revise(
        changeType: ClinicalPlanChangeType::REVISION,
        planType: ClinicalPlanType::MEDICATION,
        clinicalIntent: 'Updated medical intent',
        goals: 'New goals',
        plannedActions: 'New actions',
        instructions: null,
        followUp: '3 days',
        indicationDiagnosisId: null,
        indicationProblemId: null,
        reason: null,
        authorUserId: (string) Str::uuid7(),
        changeOccurredAt: CarbonImmutable::now(),
        changeRecordedAt: CarbonImmutable::now(),
    );

    expect($plan->plan_type)->toBe('MEDICATION')
        ->and($plan->clinical_intent)->toBe('Updated medical intent')
        ->and($plan->goals)->toBe('New goals')
        ->and($plan->instructions)->toBeNull();

    $history = $plan->releasePendingHistory();
    expect($history)->toHaveCount(1)
        ->and($history[0]->change_type)->toBe('REVISION')
        ->and($history[0]->clinical_intent)->toBe('Standard treatment intent')
        ->and($history[0]->plan_type)->toBe('GENERAL')
        ->and($history[0]->reason)->toBeNull();

    $events = $plan->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ClinicalPlanUpdated::class)
        ->and($events[0]->changeType)->toBe('REVISION');
});

test('revise() with CORRECTION requires reason and records ClinicalPlanUpdated', function () {
    $plan = makePlan();

    expect(fn () => $plan->revise(
        changeType: ClinicalPlanChangeType::CORRECTION,
        planType: ClinicalPlanType::GENERAL,
        clinicalIntent: 'Corrected intent',
        goals: null,
        plannedActions: null,
        instructions: null,
        followUp: null,
        indicationDiagnosisId: null,
        indicationProblemId: null,
        reason: '',
        authorUserId: (string) Str::uuid7(),
        changeOccurredAt: CarbonImmutable::now(),
        changeRecordedAt: CarbonImmutable::now(),
    ))->toThrow(InvalidArgumentException::class, 'reason is required for CORRECTION');

    $plan->revise(
        changeType: ClinicalPlanChangeType::CORRECTION,
        planType: ClinicalPlanType::PROCEDURE,
        clinicalIntent: 'Corrected surgical intent',
        goals: null,
        plannedActions: null,
        instructions: null,
        followUp: null,
        indicationDiagnosisId: null,
        indicationProblemId: null,
        reason: 'Misdiagnosed originally',
        authorUserId: (string) Str::uuid7(),
        changeOccurredAt: CarbonImmutable::now(),
        changeRecordedAt: CarbonImmutable::now(),
    );

    $history = $plan->releasePendingHistory();
    expect($history)->toHaveCount(1)
        ->and($history[0]->change_type)->toBe('CORRECTION')
        ->and($history[0]->reason)->toBe('Misdiagnosed originally');
});

test('close() completes plan without reason or discontinues plan with reason', function () {
    $plan1 = makePlan();
    $plan1->close(ClinicalPlanStatus::COMPLETED, null);
    expect($plan1->status)->toBe(ClinicalPlanStatus::COMPLETED)
        ->and($plan1->isCompleted())->toBeTrue()
        ->and($plan1->closed_reason)->toBeNull();

    expect(fn () => makePlan()->close(ClinicalPlanStatus::COMPLETED, 'Unwanted reason'))
        ->toThrow(InvalidArgumentException::class, 'reason is forbidden when outcome is COMPLETED');

    $plan2 = makePlan();
    $plan2->close(ClinicalPlanStatus::DISCONTINUED, 'Patient condition changed');
    expect($plan2->status)->toBe(ClinicalPlanStatus::DISCONTINUED)
        ->and($plan2->isDiscontinued())->toBeTrue()
        ->and($plan2->closed_reason)->toBe('Patient condition changed');

    expect(fn () => makePlan()->close(ClinicalPlanStatus::DISCONTINUED, ''))
        ->toThrow(InvalidArgumentException::class, 'reason is required when outcome is DISCONTINUED');

    expect(fn () => $plan1->close(ClinicalPlanStatus::DISCONTINUED, 'Cannot close again'))
        ->toThrow(IllegalClinicalPlanStatusTransition::class);
});
