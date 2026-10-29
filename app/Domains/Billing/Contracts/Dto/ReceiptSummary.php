<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One Receipt (20-billing-and-payments-domain.md §91; ADR-191 B5-C). Returned
 * by `GetReceipt` via `ReceiptReader` — the reprint source (§93). NOT
 * `#[TypeScript]`.
 */
final readonly class ReceiptSummary
{
    public function __construct(
        public string $receiptId,
        public string $receiptNumber,
        public string $paymentId,
        public string $financialAccountId,
        public string $facilityId,
        public string $patientId,
        public string $method,
        public string $amount,
        public string $currency,
        public string $status,
        public string $issuedAt,
        public string $issuedByUserId,
        public ?string $reversedAt,
    ) {}
}
