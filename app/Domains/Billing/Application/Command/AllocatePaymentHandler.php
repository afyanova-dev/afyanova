<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Exception\AllocationAccountMismatch;
use App\Domains\Billing\Domain\Exception\AllocationConflict;
use App\Domains\Billing\Domain\Exception\IllegalInvoiceTransition;
use App\Domains\Billing\Domain\Exception\IllegalPaymentTransition;
use App\Domains\Billing\Domain\Exception\OverAllocation;
use App\Domains\Billing\Domain\InvoiceRepository;
use App\Domains\Billing\Domain\Model\Invoice;
use App\Domains\Billing\Domain\Model\PaymentAllocation;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\PaymentAllocationRepository;
use App\Domains\Billing\Domain\PaymentRepository;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `AllocatePayment` (ADR-191 B5-A).
 *
 * Lock the payment + the invoice → both on the same account → the payment
 * RECORDED, the invoice ISSUED → Σ(active allocations) + this amount must not
 * exceed `payment.amount` or `invoice.net` → `PaymentAllocation::create` → save
 * (on the partial-unique race → `AllocationConflict`). NO ledger entry.
 * Idempotent on `allocationId` (a pre-check) in addition to the bus key.
 */
final class AllocatePaymentHandler
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly PaymentAllocationRepository $allocations,
        private readonly InvoiceRepository $invoices,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(AllocatePayment $command): void
    {
        if ($this->allocations->exists($command->allocationId)) {
            return;
        }

        $payment = $this->payments->getByIdForUpdate($command->paymentId);
        if (! $payment->isRecorded()) {
            throw IllegalPaymentTransition::fromStatus('AllocatePayment', (string) $payment->status);
        }

        $invoice = $this->invoices->getByIdForUpdate($command->invoiceId);
        if ((string) $invoice->status !== Invoice::STATUS_ISSUED) {
            throw IllegalInvoiceTransition::fromStatus('AllocatePayment', (string) $invoice->status);
        }

        if ((string) $payment->financial_account_id !== (string) $invoice->financial_account_id) {
            throw AllocationAccountMismatch::of($command->paymentId, $command->invoiceId);
        }

        $amount = Money::of($command->amount);

        $paymentTotal = Money::add($this->allocations->activeAmountForPayment($command->paymentId), $amount);
        if (! Money::gte(Money::of((string) $payment->amount), $paymentTotal)) {
            throw OverAllocation::payment($command->paymentId);
        }

        $invoiceTotal = Money::add($this->allocations->activeAmountForInvoice($command->invoiceId), $amount);
        if (! Money::gte(Money::of((string) $invoice->net), $invoiceTotal)) {
            throw OverAllocation::invoice($command->invoiceId);
        }

        $now = CarbonImmutable::now();

        $allocation = PaymentAllocation::create(
            id: $command->allocationId,
            tenantId: $this->context->tenantId(),
            paymentId: $command->paymentId,
            invoiceId: $command->invoiceId,
            financialAccountId: (string) $payment->financial_account_id,
            amount: $amount,
            occurredAt: $now,
            recordedAt: $now,
        );

        try {
            $this->allocations->save($allocation);
        } catch (UniqueConstraintViolationException) {
            throw AllocationConflict::of($command->paymentId, $command->invoiceId);
        }
    }
}
