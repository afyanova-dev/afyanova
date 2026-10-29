<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Repository;

use App\Domains\Insurance\Domain\Model\Payer;
use App\Domains\Insurance\Domain\PayerRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PayerRepository} (IC §9; ADR-198 D-G).
 */
final class EloquentPayerRepository extends EloquentRepository implements PayerRepository
{
    protected function model(): string
    {
        return Payer::class;
    }

    public function getById(string $id): Payer
    {
        return Payer::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Payer::class, $id);
    }

    public function getByIdForUpdate(string $id): Payer
    {
        return Payer::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Payer::class, $id);
    }

    public function existsByCode(string $code): bool
    {
        return Payer::query()->where('code', $code)->exists();
    }
}
