<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One Payment (20-billing-and-payments-domain.md §57 / §288; ADR-191 B5-B).
 * Returned by `GetPayment` / `GetAccountPayments` via `PaymentReader`. NOT
 * `#[TypeScript]`. All money fields are scale-2 numeric strings.
 *
 * `unallocatedAmount` = `amount − Σ(active allocations)`; in Bill.5b there are
 * no allocations so it always equals `amount` (Bill.5c makes it move).
 */
final readonly class PaymentSummary
{
    public function __construct(
        public string $paymentId,
        public string $financialAccountId,
        public string $facilityId,
        public string $patientId,
        public string $receiptNumber,
        public string $method,
        public string $amount,
        public string $currency,
        public string $status,
        public string $unallocatedAmount,
        public ?string $paymentReference,
        public string $collectedAtFacilityId,
        public string $collectedByUserId,
        public ?string $cashSessionId,
        public string $receivedAt,
        public string $recordedAt,
        public ?string $reversalId,
        public ?string $reversedAt,
        public ?string $reverseReasonCode,
    ) {}
}
