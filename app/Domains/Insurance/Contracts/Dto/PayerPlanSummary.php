<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * One payer-plan master entry (21-insurance-and-payer-domain.md §12; ADR-198
 * D-I). Returned by `GetPayerPlan` / `SearchPayerPlans` via `PlanReader`. NOT
 * #[TypeScript].
 */
final readonly class PayerPlanSummary
{
    public function __construct(
        public string $planId,
        public string $payerId,
        public string $code,
        public string $name,
        public string $status,
        public ?string $statusReason,
        public ?string $effectiveFrom,
        public ?string $effectiveTo,
        public string $recordedAt,
    ) {}
}
