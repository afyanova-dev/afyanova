<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * The resolved base price for one billing service at one facility on one date
 * (20-billing-and-payments-domain.md §17, §233–§235; ADR-188 D-D).
 *
 *   resolvedScope = FACILITY        — a facility-specific tariff applied
 *                 = TENANT_DEFAULT  — the tenant-wide default applied
 *                 = NONE            — the service exists but has no effective
 *                                     price (unpriced ≠ no-charge — §28 / §106);
 *                                     `amount` is null.
 *
 * `amount` is a scale-2 numeric string (the price for one billable unit — Bill.3
 * owns unit × quantity + rounding). `serviceStatus` is returned so the caller
 * (Bill.3) can gate on an INACTIVE service — this DTO is status-agnostic. NOT
 * `#[TypeScript]`.
 */
final readonly class TariffResolution
{
    public function __construct(
        public string $billingServiceId,
        public ?string $facilityId,
        public string $resolvedScope,
        public ?string $amount,
        public string $currency,
        public ?string $effectiveFrom,
        public ?string $tariffRowId,
        public string $serviceStatus,
    ) {}
}
