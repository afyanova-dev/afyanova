<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CloseClinicalPlan;
use App\Domains\Clinical\Domain\Exception\IllegalClinicalPlanStatusTransition;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('CloseClinicalPlan COMPLETED sets status, clears closed_reason, records audit', function () {
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

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_plans')->where('id', $planId)->first());
    expect($row->status)->toBe('COMPLETED')
        ->and($row->closed_reason)->toBeNull();

    $historyCount = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_plan_history')->where('plan_id', $planId)->count());
    expect($historyCount)->toBe(0);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'clinical.plans.closed')
        ->where('target_id', $planId)
        ->first());
    expect($audit)->not->toBeNull();
});

test('CloseClinicalPlan DISCONTINUED sets status and closed_reason', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $planId = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CloseClinicalPlan(
            clinicalPlanId: $planId,
            actorId: (string) $user->id,
            outcome: ClinicalPlanStatus::DISCONTINUED,
            reason: 'Patient transferred to other specialty',
        ),
    ));

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_plans')->where('id', $planId)->first());
    expect($row->status)->toBe('DISCONTINUED')
        ->and($row->closed_reason)->toBe('Patient transferred to other specialty');
});

test('CloseClinicalPlan rejects closing an already closed plan', function () {
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

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CloseClinicalPlan(
            clinicalPlanId: $planId,
            actorId: (string) $user->id,
            outcome: ClinicalPlanStatus::DISCONTINUED,
            reason: 'Try again',
        ),
    )))->toThrow(IllegalClinicalPlanStatusTransition::class);
});
