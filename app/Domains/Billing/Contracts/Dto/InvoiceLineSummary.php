<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One invoice line (20-billing-and-payments-domain.md §35; ADR-190 D-D;
 * ADR-201 D-D). NOT `#[TypeScript]`. `quantity` is a plain decimal string;
 * money fields are scale-2 numeric strings.
 *
 * `serviceCategory` / `payerResponsibility` / `patientResponsibility` /
 * `coverageStatus` are null on a SELF_PAY invoice (ADR-201 — the coverage split
 * from `SetInvoiceCoverage`).
 */
final readonly class InvoiceLineSummary
{
    public function __construct(
        public string $lineId,
        public string $chargeId,
        public string $description,
        public string $billingServiceCode,
        public string $quantity,
        public string $unitPrice,
        public string $amount,
        public bool $lineActive,
        public bool $chargeReversed,
        public ?string $serviceCategory,
        public ?string $payerResponsibility,
        public ?string $patientResponsibility,
        public ?string $coverageStatus,
    ) {}
}
