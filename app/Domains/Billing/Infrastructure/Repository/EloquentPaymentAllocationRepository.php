<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\Model\PaymentAllocation;
use App\Domains\Billing\Domain\Money;
use App\Domains\Billing\Domain\PaymentAllocationRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PaymentAllocationRepository} (IC §9; ADR-191 B5-A).
 */
final class EloquentPaymentAllocationRepository extends EloquentRepository implements PaymentAllocationRepository
{
    protected function model(): string
    {
        return PaymentAllocation::class;
    }

    public function getByIdForUpdate(string $id): PaymentAllocation
    {
        return PaymentAllocation::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(PaymentAllocation::class, $id);
    }

    public function liveForPaymentAndInvoiceForUpdate(string $paymentId, string $invoiceId): ?PaymentAllocation
    {
        return PaymentAllocation::query()
            ->where('payment_id', $paymentId)
            ->where('invoice_id', $invoiceId)
            ->where('allocation_active', true)
            ->lockForUpdate()
            ->first();
    }

    public function liveForPaymentForUpdate(string $paymentId): array
    {
        return array_values(
            PaymentAllocation::query()
                ->where('payment_id', $paymentId)
                ->where('allocation_active', true)
                ->orderBy('recorded_at')->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->all()
        );
    }

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

    public function exists(string $id): bool
    {
        return PaymentAllocation::query()->whereKey($id)->exists();
    }
}
