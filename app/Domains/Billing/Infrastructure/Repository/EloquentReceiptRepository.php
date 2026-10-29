<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\Exception\ReceiptNotFound;
use App\Domains\Billing\Domain\Model\Receipt;
use App\Domains\Billing\Domain\ReceiptRepository;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of {@see ReceiptRepository} (IC §9; ADR-191 B5-C).
 */
final class EloquentReceiptRepository extends EloquentRepository implements ReceiptRepository
{
    protected function model(): string
    {
        return Receipt::class;
    }

    public function getByPaymentIdForUpdate(string $paymentId): Receipt
    {
        return Receipt::query()->where('payment_id', $paymentId)->lockForUpdate()->first()
            ?? throw ReceiptNotFound::forPayment($paymentId);
    }
}
