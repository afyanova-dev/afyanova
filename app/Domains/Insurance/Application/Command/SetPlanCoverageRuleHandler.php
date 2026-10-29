<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Exception\CoverageRuleChangeConflict;
use App\Domains\Insurance\Domain\Exception\CoverageRuleEffectiveDateNotAfterCurrent;
use App\Domains\Insurance\Domain\Model\PlanCoverageRule;
use App\Domains\Insurance\Domain\PlanCoverageRuleRepository;
use App\Domains\Insurance\Domain\PlanReader;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `SetPlanCoverageRule` (ADR-200 D-B) — the `SetTariffHandler`
 * shape. plan exists (`PayerPlanNotFound`) → row-lock the open rule for the
 * scope → strict-after guard + `supersede()` → insert the new CURRENT row;
 * the partial-unique violation (a concurrent first write) →
 * `CoverageRuleChangeConflict`.
 *
 * `PlanCoverageRuleRepository` is the FIRST repository param so the bus
 * resolves `PlanCoverageRulePolicy`; `PlanReader` is a later parameter (same
 * module).
 */
final class SetPlanCoverageRuleHandler
{
    public function __construct(
        private readonly PlanCoverageRuleRepository $rules,
        private readonly PlanReader $plans,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(SetPlanCoverageRule $command): void
    {
        $this->plans->summary($command->planId);   // PayerPlanNotFound if absent

        $open = $this->rules->openRowForScopeForUpdate($command->planId, $command->serviceCategory);

        if ($open !== null) {
            if (! $command->effectiveFrom->gt($open->effective_from)) {
                throw CoverageRuleEffectiveDateNotAfterCurrent::dates(
                    $command->effectiveFrom->toDateString(),
                    $open->effective_from->toDateString(),
                );
            }
            $open->supersede($command->effectiveFrom);
            $this->rules->save($open);
        }

        $now = CarbonImmutable::now();

        $rule = PlanCoverageRule::open(
            id: $command->ruleId,
            tenantId: $this->context->tenantId(),
            planId: $command->planId,
            serviceCategory: $command->serviceCategory,
            coverageStatus: $command->coverageStatus,
            coveragePercent: $command->coveragePercent,
            coPayAmount: $command->coPayAmount,
            coPayCurrency: 'TZS',
            fixedBenefitAmount: $command->fixedBenefitAmount,
            authorizationRequired: $command->authorizationRequired,
            effectiveFrom: $command->effectiveFrom,
            createdByUserId: $command->actorId,
            occurredAt: $now,
            recordedAt: $now,
        );

        try {
            $this->rules->save($rule);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'ins_plan_coverage_rules_one_open_per_scope')) {
                throw CoverageRuleChangeConflict::forScope($command->planId, $command->serviceCategory);
            }
            throw $e;
        }
    }
}
