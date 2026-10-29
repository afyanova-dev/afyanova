<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\ChargeReversalRepository;
use App\Domains\Billing\Domain\Model\ChargeReversal;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see ChargeReversalRepository} (IC §9; ADR-189 D-D).
 */
final class EloquentChargeReversalRepository extends EloquentRepository implements ChargeReversalRepository
{
    protected function model(): string
    {
        return ChargeReversal::class;
    }

    public function getById(string $id): ChargeReversal
    {
        return ChargeReversal::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(ChargeReversal::class, $id);
    }

    public function getByIdForUpdate(string $id): ChargeReversal
    {
        return ChargeReversal::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(ChargeReversal::class, $id);
    }

    public function existsForCharge(string $chargeId): bool
    {
        return ChargeReversal::query()->where('charge_id', $chargeId)->exists();
    }
}
