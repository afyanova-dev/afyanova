<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One facility charge (20-billing-and-payments-domain.md §14 / §299; ADR-189 D-G).
 * Returned by `GetCharge` / `GetAccountCharges` via `ChargeReader`. NOT
 * `#[TypeScript]`. All money fields are scale-2 numeric strings; the charge is
 * recompute-auditable from `unitPrice` + `quantity` (§195).
 */
final readonly class ChargeSummary
{
    public function __construct(
        public string $chargeId,
        public string $financialAccountId,
        public string $facilityId,
        public string $patientId,
        public string $billingServiceId,
        public string $billingServiceCode,
        public string $billableEventId,
        public string $sourceRef,
        public string $quantity,
        public string $unitPrice,
        public string $tariffRowId,
        public string $resolvedScope,
        public string $amount,
        public string $currency,
        public string $status,
        public ?string $reversalId,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
