<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\PaymentAllocationRepository;
use App\Domains\Billing\Domain\PaymentRepository;

/**
 * Orchestrates `UnallocatePayment` (ADR-191 B5-A). Lock the payment (policy
 * subject) → find the live (payment, invoice) allocation → `deactivate()` +
 * save. Idempotent — a no-op when there is no live allocation. NO ledger entry.
 */
final class UnallocatePaymentHandler
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentAllocationRepository $allocations,
    ) {}

    public function __invoke(UnallocatePayment $command): void
    {
        $this->payments->getByIdForUpdate($command->paymentId);

        $allocation = $this->allocations->liveForPaymentAndInvoiceForUpdate($command->paymentId, $command->invoiceId);
        if ($allocation === null) {
            return;
        }

        $allocation->deactivate();
        $this->allocations->save($allocation);
    }
}
