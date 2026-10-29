<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One immutable tariff row (20-billing-and-payments-domain.md §19–§24;
 * ADR-188). Rows are never mutated — a new price closes the prior open row,
 * so history reads newest-first. NOT `#[TypeScript]`.
 */
final readonly class TariffHistoryEntry
{
    public function __construct(
        public string $tariffId,
        public string $billingServiceId,
        public string $resolvedScope,
        public string $amount,
        public string $currency,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public string $status,
        public string $recordedAt,
    ) {}
}
