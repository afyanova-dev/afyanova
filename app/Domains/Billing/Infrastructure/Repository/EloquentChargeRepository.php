<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\ChargeRepository;
use App\Domains\Billing\Domain\Model\Charge;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see ChargeRepository} (IC §9; ADR-189 D-C).
 */
final class EloquentChargeRepository extends EloquentRepository implements ChargeRepository
{
    protected function model(): string
    {
        return Charge::class;
    }

    public function getById(string $id): Charge
    {
        return Charge::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Charge::class, $id);
    }

    public function getByIdForUpdate(string $id): Charge
    {
        return Charge::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Charge::class, $id);
    }
}
