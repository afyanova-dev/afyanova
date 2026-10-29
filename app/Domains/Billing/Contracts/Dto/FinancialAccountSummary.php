<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One Financial Account (20-billing-and-payments-domain.md §7, §300; ADR-187 D-E).
 * Returned by `GetFinancialAccount` via `FinancialAccountReader`.
 *
 * NOT `#[TypeScript]` (no PHASE 10 frontend consumer, ADR-124). Carries no
 * balance — that is `GetOutstandingBalance` / `OutstandingBalanceSummary`.
 */
final readonly class FinancialAccountSummary
{
    public function __construct(
        public string $financialAccountId,
        public string $accountNumber,
        public string $facilityId,
        public string $patientId,
        public string $status,
        public string $currency,
        public string $openedAt,
        public string $openedByUserId,
        public ?string $closedAt,
        public ?string $closedByUserId,
        public ?string $closeReasonCode,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
