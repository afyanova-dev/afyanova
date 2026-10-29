<?php

declare(strict_types=1);

namespace App\Domains\Billing\Contracts\Dto;

/**
 * One payment allocation (20-billing-and-payments-domain.md §65 / §294; ADR-191
 * B5-A). Returned by `GetPaymentAllocations` via `PaymentAllocationReader`. NOT
 * `#[TypeScript]`.
 */
final readonly class PaymentAllocationSummary
{
    public function __construct(
        public string $allocationId,
        public string $paymentId,
        public string $invoiceId,
        public string $financialAccountId,
        public string $amount,
        public bool $active,
        public string $recordedAt,
    ) {}
}
