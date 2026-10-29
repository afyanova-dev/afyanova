<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Reader;

use App\Domains\Billing\Contracts\Dto\PaymentAllocationSummary;
use App\Domains\Billing\Domain\Model\PaymentAllocation;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\PaymentAllocationReader;

/**
 * Eloquent implementation of {@see PaymentAllocationReader} (IC §6.2; ADR-191
 * B5-A / B5-D).
 */
final class EloquentPaymentAllocationReader implements PaymentAllocationReader
{
    public function activeAmountForPayment(string $paymentId): string
    {
        return Money::of((string) PaymentAllocation::query()
            ->where('payment_id', $paymentId)
            ->where('allocation_active', true)
            ->sum('amount'));
    }

    public function activeAmountForInvoice(string $invoiceId): string
    {
        return Money::of((string) PaymentAllocation::query()
            ->where('invoice_id', $invoiceId)
            ->where('allocation_active', true)
            ->sum('amount'));
    }

    public function invoiceHasActiveAllocation(string $invoiceId): bool
    {
        return PaymentAllocation::query()
            ->where('invoice_id', $invoiceId)
            ->where('allocation_active', true)
            ->exists();
    }

    public function forPayment(string $paymentId): array
    {
        return array_values(
            PaymentAllocation::query()
                ->where('payment_id', $paymentId)
                ->orderByDesc('recorded_at')->orderByDesc('id')
                ->get()
                ->map(static fn (PaymentAllocation $a): PaymentAllocationSummary => new PaymentAllocationSummary(
                    allocationId: (string) $a->id,
                    paymentId: (string) $a->payment_id,
                    invoiceId: (string) $a->invoice_id,
                    financialAccountId: (string) $a->financial_account_id,
                    amount: Money::of((string) $a->amount),
                    active: (bool) $a->allocation_active,
                    recordedAt: $a->recorded_at->toIso8601String(),
                ))
                ->all()
        );
    }
}
