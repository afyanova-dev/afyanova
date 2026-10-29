<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Reader;

use App\Domains\Billing\Contracts\Dto\PaymentSummary;
use App\Domains\Billing\Domain\Exception\PaymentNotFound;
use App\Domains\Billing\Domain\Model\Payment;
use App\Domains\Billing\Domain\Model\Receipt;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\PaymentAllocationReader;
use App\Domains\Billing\Domain\PaymentReader;

/**
 * Eloquent implementation of {@see PaymentReader} (IC §6.2; ADR-191 B5-B).
 * `unallocatedAmount` = `amount − Σ(active bill_payment_allocations)` (Bill.5c).
 */
final class EloquentPaymentReader implements PaymentReader
{
    public function __construct(private readonly PaymentAllocationReader $allocations) {}

    public function summary(string $paymentId): PaymentSummary
    {
        $payment = Payment::query()->whereKey($paymentId)->first()
            ?? throw PaymentNotFound::id($paymentId);

        return $this->toSummary($payment);
    }

    public function forAccount(string $financialAccountId): array
    {
        return array_values(
            Payment::query()
                ->where('financial_account_id', $financialAccountId)
                ->orderByDesc('recorded_at')->orderByDesc('id')
                ->get()
                ->map(fn (Payment $p): PaymentSummary => $this->toSummary($p))
                ->all()
        );
    }

    private function toSummary(Payment $payment): PaymentSummary
    {
        $amount = Money::of((string) $payment->amount);

        return new PaymentSummary(
            paymentId: (string) $payment->id,
            financialAccountId: (string) $payment->financial_account_id,
            facilityId: (string) $payment->facility_id,
            patientId: (string) $payment->patient_id,
            receiptNumber: $this->receiptNumberFor((string) $payment->id),
            method: (string) $payment->method,
            amount: $amount,
            currency: (string) $payment->currency,
            status: (string) $payment->status,
            unallocatedAmount: Money::subtract($amount, $this->allocations->activeAmountForPayment((string) $payment->id)),
            paymentReference: $payment->payment_reference !== null ? (string) $payment->payment_reference : null,
            collectedAtFacilityId: (string) $payment->collected_at_facility_id,
            collectedByUserId: (string) $payment->collected_by_user_id,
            cashSessionId: $payment->cash_session_id !== null ? (string) $payment->cash_session_id : null,
            receivedAt: $payment->received_at->toIso8601String(),
            recordedAt: $payment->recorded_at->toIso8601String(),
            reversalId: $payment->reversal_id !== null ? (string) $payment->reversal_id : null,
            reversedAt: $payment->reversed_at?->toIso8601String(),
            reverseReasonCode: $payment->reverse_reason_code,
        );
    }

    private function receiptNumberFor(string $paymentId): string
    {
        return (string) Receipt::query()
            ->where('payment_id', $paymentId)
            ->value('receipt_number');
    }
}
