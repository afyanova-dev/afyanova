<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Service;

use App\Domains\Insurance\Contracts\Dto\CoverageDecision;
use App\Domains\Insurance\Contracts\Dto\MemberCoverageSummary;
use App\Domains\Insurance\Contracts\Dto\PlanCoverageRuleSummary;
use App\Domains\Insurance\Domain\MemberCoverageReader;
use App\Domains\Insurance\Domain\Money;
use App\Domains\Insurance\Domain\PayerTariffReader;
use App\Domains\Insurance\Domain\PlanCoverageRuleReader;
use App\Domains\Insurance\Domain\ValueObject\CoverageConfidence;
use App\Domains\Insurance\Domain\ValueObject\CoverageDecisionStatus;
use App\Domains\Insurance\Domain\ValueObject\CoverageRuleOutcome;
use App\Domains\Insurance\Domain\ValueObject\CoverageSource;
use App\Domains\Insurance\Domain\ValueObject\MemberCoverageStatus;
use Carbon\CarbonImmutable;

/**
 * Resolves a `CoverageDecision` from local, rule-based data (ADR-200 D-C;
 * ADR-198 D-F — LOCAL evaluation only). No side effects, no persistence.
 *
 * Depends on reader PORTS (interfaces in `Domain/`) — no Eloquent, no
 * cross-domain call (`ins_member_coverages`, `ins_plan_coverage_rules` and
 * `ins_payer_tariffs` are all Insurance's).
 */
final class CoverageEvaluator
{
    public function __construct(
        private readonly MemberCoverageReader $coverages,
        private readonly PlanCoverageRuleReader $rules,
        private readonly CoverageCalculator $calculator,
        private readonly PayerTariffReader $tariffs,
    ) {}

    public function evaluate(
        string $patientId,
        string $planId,
        string $serviceCategory,
        ?string $chargeAmount,
        CarbonImmutable $onDate,
        ?string $facilityId = null,
    ): CoverageDecision {
        $onDateString = $onDate->toDateString();
        $currency = 'TZS';

        $coverage = $this->activeCoverageFor($patientId, $planId, $onDate);

        if ($coverage === null) {
            return $this->decision(
                eligible: false,
                coverageStatus: CoverageDecisionStatus::NOT_COVERED,
                rule: null,
                authorizationRequired: false,
                payer: $chargeAmount !== null ? Money::zero() : null,
                patient: $chargeAmount !== null ? Money::of($chargeAmount) : null,
                currency: $currency,
                confidence: CoverageConfidence::CONFIRMED,
                planId: $planId,
                serviceCategory: $serviceCategory,
                memberCoverageId: null,
                onDate: $onDateString,
            );
        }

        $rule = $this->rules->effectiveRule($planId, $serviceCategory, $onDateString);

        if ($rule === null) {
            return $this->decision(
                eligible: true,
                coverageStatus: CoverageDecisionStatus::UNKNOWN,
                rule: null,
                authorizationRequired: false,
                payer: $chargeAmount !== null ? Money::zero() : null,
                patient: $chargeAmount !== null ? Money::of($chargeAmount) : null,
                currency: $currency,
                confidence: CoverageConfidence::UNKNOWN,
                planId: $planId,
                serviceCategory: $serviceCategory,
                memberCoverageId: $coverage->memberCoverageId,
                onDate: $onDateString,
            );
        }

        $authorizationRequired = $rule->coverageStatus === CoverageRuleOutcome::REQUIRES_AUTHORIZATION
            || $rule->authorizationRequired;

        $payer = null;
        $patient = null;
        $allowedAmount = null;
        $contractualAdjustment = null;
        if ($chargeAmount !== null) {
            $tariff = $this->tariffs->effectiveTariffForPayer(
                $coverage->payerId,
                $serviceCategory,
                $onDateString,
                $facilityId,
            );
            $contractedRate = $tariff !== null ? Money::of($tariff->amount) : null;

            $split = $this->calculator->split(
                $rule->coverageStatus,
                $rule->coveragePercent,
                $rule->coPayAmount !== null ? Money::of($rule->coPayAmount) : null,
                $rule->fixedBenefitAmount !== null ? Money::of($rule->fixedBenefitAmount) : null,
                Money::of($chargeAmount),
                $contractedRate,
            );
            $payer = $split['payer'];
            $patient = $split['patient'];
            $allowedAmount = $split['allowedAmount'];
            $contractualAdjustment = $split['contractualAdjustment'];
        }

        return $this->decision(
            eligible: true,
            coverageStatus: $this->derivedStatus($rule),
            rule: $rule,
            authorizationRequired: $authorizationRequired,
            payer: $payer,
            patient: $patient,
            currency: $currency,
            confidence: $rule->coverageStatus === CoverageRuleOutcome::REQUIRES_AUTHORIZATION
                ? CoverageConfidence::PROVISIONAL
                : CoverageConfidence::CONFIRMED,
            planId: $planId,
            serviceCategory: $serviceCategory,
            memberCoverageId: $coverage->memberCoverageId,
            onDate: $onDateString,
            allowedAmount: $allowedAmount,
            contractualAdjustment: $contractualAdjustment,
        );
    }

    private function activeCoverageFor(string $patientId, string $planId, CarbonImmutable $onDate): ?MemberCoverageSummary
    {
        foreach ($this->coverages->forPatient($patientId) as $coverage) {
            if ($coverage->planId !== $planId || $coverage->status !== MemberCoverageStatus::ACTIVE) {
                continue;
            }
            if (CarbonImmutable::parse($coverage->effectiveFrom)->gt($onDate)) {
                continue;
            }
            if ($coverage->effectiveTo !== null && CarbonImmutable::parse($coverage->effectiveTo)->lt($onDate)) {
                continue;
            }

            return $coverage;
        }

        return null;
    }

    private function derivedStatus(PlanCoverageRuleSummary $rule): string
    {
        if ($rule->coverageStatus === CoverageRuleOutcome::NOT_COVERED) {
            return CoverageDecisionStatus::NOT_COVERED;
        }
        if ($rule->coverageStatus === CoverageRuleOutcome::REQUIRES_AUTHORIZATION) {
            return CoverageDecisionStatus::REQUIRES_AUTHORIZATION;
        }

        $partial = ($rule->coveragePercent !== null && $rule->coveragePercent < 100)
            || ($rule->coPayAmount !== null && ! Money::isZero($rule->coPayAmount))
            || $rule->fixedBenefitAmount !== null;

        return $partial ? CoverageDecisionStatus::PARTIALLY_COVERED : CoverageDecisionStatus::COVERED;
    }

    /**
     * @param  numeric-string|null  $payer
     * @param  numeric-string|null  $patient
     * @param  numeric-string|null  $allowedAmount
     * @param  numeric-string|null  $contractualAdjustment
     */
    private function decision(
        bool $eligible,
        string $coverageStatus,
        ?PlanCoverageRuleSummary $rule,
        bool $authorizationRequired,
        ?string $payer,
        ?string $patient,
        string $currency,
        string $confidence,
        string $planId,
        string $serviceCategory,
        ?string $memberCoverageId,
        string $onDate,
        ?string $allowedAmount = null,
        ?string $contractualAdjustment = null,
    ): CoverageDecision {
        return new CoverageDecision(
            eligible: $eligible,
            coverageStatus: $coverageStatus,
            coveragePercent: $rule?->coveragePercent,
            coPayAmount: $rule?->coPayAmount,
            fixedBenefitAmount: $rule?->fixedBenefitAmount,
            authorizationRequired: $authorizationRequired,
            payerResponsibilityEstimate: $payer,
            patientResponsibilityEstimate: $patient,
            currency: $currency,
            source: CoverageSource::LOCAL_POLICY,
            confidence: $confidence,
            planId: $planId,
            serviceCategory: $serviceCategory,
            memberCoverageId: $memberCoverageId,
            ruleEffectiveFrom: $rule?->effectiveFrom,
            evaluatedForDate: $onDate,
            allowedAmount: $allowedAmount,
            contractualAdjustmentEstimate: $contractualAdjustment,
        );
    }
}
