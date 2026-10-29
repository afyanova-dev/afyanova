<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CloseClinicalPlan;
use App\Domains\Clinical\Application\Command\UpdateClinicalPlan;
use App\Domains\Clinical\Contracts\Event\ClinicalPlanUpdated;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanChangeType;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanStatus;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanType;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('UpdateClinicalPlan with REVISION appends history row, mutates clin_plans, emits ClinicalPlanUpdated', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $planId = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'clinicalIntent' => 'Initial intent',
        'planType' => ClinicalPlanType::GENERAL,
    ]);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new UpdateClinicalPlan(
            clinicalPlanId: $planId,
            actorId: (string) $user->id,
            changeType: ClinicalPlanChangeType::REVISION,
            planType: ClinicalPlanType::MEDICATION,
            clinicalIntent: 'Revised intent',
            goals: 'New goals',
        ),
    ));

    $plan = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_plans')->where('id', $planId)->first());
    expect($plan->plan_type)->toBe('MEDICATION')
        ->and($plan->clinical_intent)->toBe('Revised intent')
        ->and($plan->goals)->toBe('New goals');

    $history = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_plan_history')->where('plan_id', $planId)->get());
    expect($history)->toHaveCount(1)
        ->and($history[0]->change_type)->toBe('REVISION')
        ->and($history[0]->clinical_intent)->toBe('Initial intent')
        ->and($history[0]->plan_type)->toBe('GENERAL')
        ->and($history[0]->reason)->toBeNull();

    $event = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ClinicalPlanUpdated::class)
        ->first());

    expect($event)->not->toBeNull();
    $payload = json_decode((string) $event->payload, true);
    expect($payload['changeType'])->toBe('REVISION')
        ->and($payload['planType'])->toBe('MEDICATION');
});

test('UpdateClinicalPlan with CORRECTION saves mandatory clinical reason to history row', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $planId = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new UpdateClinicalPlan(
            clinicalPlanId: $planId,
            actorId: (string) $user->id,
            changeType: ClinicalPlanChangeType::CORRECTION,
            planType: ClinicalPlanType::PROCEDURE,
            clinicalIntent: 'Corrected surgical approach',
            reason: 'Correction of initial assessment',
        ),
    ));

    $history = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_plan_history')->where('plan_id', $planId)->first());
    expect($history->change_type)->toBe('CORRECTION')
        ->and($history->reason)->toBe('Correction of initial assessment');
});

test('UpdateClinicalPlan is status-agnostic: can correct completed or discontinued plans', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $planId = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CloseClinicalPlan(
            clinicalPlanId: $planId,
            actorId: (string) $user->id,
            outcome: ClinicalPlanStatus::COMPLETED,
        ),
    ));

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new UpdateClinicalPlan(
            clinicalPlanId: $planId,
            actorId: (string) $user->id,
            changeType: ClinicalPlanChangeType::CORRECTION,
            planType: ClinicalPlanType::GENERAL,
            clinicalIntent: 'Corrected text after completion',
            reason: 'Documentation typo fixed',
        ),
    ));

    $plan = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_plans')->where('id', $planId)->first());
    expect($plan->status)->toBe('COMPLETED')
        ->and($plan->clinical_intent)->toBe('Corrected text after completion');
});
