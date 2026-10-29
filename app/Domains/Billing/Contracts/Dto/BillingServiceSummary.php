<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One billable-service catalog entry (20-billing-and-payments-domain.md §236;
 * ADR-188 D-D). Returned by `GetBillingService` / `SearchBillingServices` via
 * `BillingCatalogReader`. NOT `#[TypeScript]`.
 */
final readonly class BillingServiceSummary
{
    public function __construct(
        public string $billingServiceId,
        public string $code,
        public string $name,
        public string $category,
        public string $status,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
