<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\Model\WriteOff;
use App\Domains\Billing\Domain\WriteOffRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentWriteOffRepository extends EloquentRepository implements WriteOffRepository
{
    protected function model(): string
    {
        return WriteOff::class;
    }

    public function getById(string $id): WriteOff
    {
        return WriteOff::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(WriteOff::class, $id);
    }

    public function getByIdForUpdate(string $id): WriteOff
    {
        return WriteOff::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(WriteOff::class, $id);
    }

    public function exists(string $id): bool
    {
        return WriteOff::query()->whereKey($id)->exists();
    }
}
