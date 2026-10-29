<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * The result of `EvaluateCoverage` (21-insurance-and-payer-domain.md §33, §124,
 * §166, §167, §225; ADR-200 D-C). What the Ins.4 Billing estimate seam and a
 * later Financial Access engine read.
 *
 * `payerResponsibilityEstimate` / `patientResponsibilityEstimate` are
 * ILLUSTRATIVE — populated only when `EvaluateCoverage` was given a
 * `chargeAmount`; the AUTHORITATIVE split is Billing's at Ins.4 (ADR-112).
 * `ruleEffectiveFrom` is the "rule version" for a §225 snapshot (re-derivable —
 * rule rows are effective-dated and immutable). NOT #[TypeScript].
 */
final readonly class CoverageDecision
{
    public function __construct(
        public bool $eligible,
        public string $coverageStatus,
        public ?int $coveragePercent,
        public ?string $coPayAmount,
        public ?string $fixedBenefitAmount,
        public bool $authorizationRequired,
        public ?string $payerResponsibilityEstimate,
        public ?string $patientResponsibilityEstimate,
        public string $currency,
        public string $source,
        public string $confidence,
        public string $planId,
        public string $serviceCategory,
        public ?string $memberCoverageId,
        public ?string $ruleEffectiveFrom,
        public string $evaluatedForDate,
        public ?string $allowedAmount = null,
        public ?string $contractualAdjustmentEstimate = null,
    ) {}
}
