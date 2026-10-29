<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\Model\Payment;
use App\Domains\Billing\Domain\PaymentRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PaymentRepository} (IC §9; ADR-191 B5-B).
 */
final class EloquentPaymentRepository extends EloquentRepository implements PaymentRepository
{
    protected function model(): string
    {
        return Payment::class;
    }

    public function getById(string $id): Payment
    {
        return Payment::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Payment::class, $id);
    }

    public function getByIdForUpdate(string $id): Payment
    {
        return Payment::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Payment::class, $id);
    }

    public function exists(string $id): bool
    {
        return Payment::query()->whereKey($id)->exists();
    }
}
