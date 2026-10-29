<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Repository;

use App\Domains\Theatre\Domain\Model\RecoveryHandoff;
use App\Domains\Theatre\Domain\RecoveryHandoffRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of RecoveryHandoffRepository (ADR-156 D-D).
 */
final class EloquentRecoveryHandoffRepository extends EloquentRepository implements RecoveryHandoffRepository
{
    protected function model(): string
    {
        return RecoveryHandoff::class;
    }

    public function getById(string $id): RecoveryHandoff
    {
        return RecoveryHandoff::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(RecoveryHandoff::class, $id);
    }

    public function getByIdForUpdate(string $id): RecoveryHandoff
    {
        return RecoveryHandoff::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(RecoveryHandoff::class, $id);
    }
}
