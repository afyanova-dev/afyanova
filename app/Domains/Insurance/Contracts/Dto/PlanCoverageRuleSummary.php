<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * One plan coverage rule row (21-insurance-and-payer-domain.md §31–§45; ADR-200
 * D-D). Returned by `GetPlanCoverageRules` and used internally by
 * `CoverageEvaluator`. NOT #[TypeScript].
 */
final readonly class PlanCoverageRuleSummary
{
    public function __construct(
        public string $ruleId,
        public string $planId,
        public string $serviceCategory,
        public string $coverageStatus,
        public ?int $coveragePercent,
        public ?string $coPayAmount,
        public string $coPayCurrency,
        public ?string $fixedBenefitAmount,
        public bool $authorizationRequired,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public string $status,
        public string $recordedAt,
    ) {}
}
