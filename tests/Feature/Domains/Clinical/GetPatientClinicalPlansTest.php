<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CloseClinicalPlan;
use App\Domains\Clinical\Application\Query\GetPatientClinicalPlans;
use App\Domains\Clinical\Contracts\Dto\ClinicalPlanSummary;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('GetPatientClinicalPlans returns patient plans scoped to in-scope facilities and status', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $plan1 = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'clinicalIntent' => 'Plan 1 Active',
    ]);
    $plan2 = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'clinicalIntent' => 'Plan 2 Completed',
    ]);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CloseClinicalPlan(
            clinicalPlanId: $plan2,
            actorId: (string) $user->id,
            outcome: ClinicalPlanStatus::COMPLETED,
        ),
    ));

    /** @var list<ClinicalPlanSummary> $all */
    $all = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(
        new GetPatientClinicalPlans($patientId),
    ));
    expect($all)->toHaveCount(2);

    /** @var list<ClinicalPlanSummary> $activeOnly */
    $activeOnly = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(
        new GetPatientClinicalPlans($patientId, ClinicalPlanStatus::ACTIVE),
    ));
    expect($activeOnly)->toHaveCount(1)
        ->and($activeOnly[0]->planId)->toBe($plan1);

    /** @var list<ClinicalPlanSummary> $completedOnly */
    $completedOnly = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(
        new GetPatientClinicalPlans($patientId, ClinicalPlanStatus::COMPLETED),
    ));
    expect($completedOnly)->toHaveCount(1)
        ->and($completedOnly[0]->planId)->toBe($plan2);
});
