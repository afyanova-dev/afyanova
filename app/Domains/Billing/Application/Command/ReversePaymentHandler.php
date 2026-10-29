<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\LedgerPoster;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\PaymentAllocationRepository;
use App\Domains\Billing\Domain\PaymentRepository;
use App\Domains\Billing\Domain\ReceiptRepository;
use App\Domains\Billing\Domain\ValueObject\LedgerEntryType;
use App\Domains\Billing\Domain\ValueObject\LedgerSourceType;
use Illuminate\Support\Str;

/**
 * Orchestrates `ReversePayment` (ADR-191 B5-C / B5-A).
 *
 * `getByIdForUpdate` → no-op on an already-REVERSED payment → `Payment::reverse`
 * (records `PaymentReversed`) → save → ONE compensating `PAYMENT` / `DEBIT`
 * ledger entry (`source_type 'PAYMENT_REVERSAL'`) → cascade-deactivate every
 * live allocation of the payment (Bill.5c — releases the invoices) → flag the
 * receipt `REVERSED` (§94 — preserved, not deleted).
 */
final class ReversePaymentHandler
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly ReceiptRepository $receipts,
        private readonly PaymentAllocationRepository $allocations,
        private readonly LedgerPoster $ledger,
    ) {}

    public function __invoke(ReversePayment $command): void
    {
        $payment = $this->payments->getByIdForUpdate($command->paymentId);

        if ($payment->isReversed()) {
            return;
        }

        $reversalId = (string) Str::uuid7();

        $payment->reverse($reversalId, $command->actorId, $command->reasonCode);
        $this->payments->save($payment);

        foreach ($this->allocations->liveForPaymentForUpdate($command->paymentId) as $allocation) {
            $allocation->deactivate();
            $this->allocations->save($allocation);
        }

        $this->ledger->post(
            financialAccountId: (string) $payment->financial_account_id,
            facilityId: (string) $payment->facility_id,
            entryType: LedgerEntryType::PAYMENT,
            direction: 'DEBIT',
            amount: Money::of((string) $payment->amount),
            currency: (string) $payment->currency,
            sourceType: LedgerSourceType::PAYMENT_REVERSAL,
            sourceId: $reversalId,
        );

        $receipt = $this->receipts->getByPaymentIdForUpdate($command->paymentId);
        $receipt->markReversed();
        $this->receipts->save($receipt);
    }
}
