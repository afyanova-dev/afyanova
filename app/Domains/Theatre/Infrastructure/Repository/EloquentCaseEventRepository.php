<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Repository;

use App\Domains\Theatre\Domain\CaseEventRepository;
use App\Domains\Theatre\Domain\Model\CaseEvent;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of CaseEventRepository (ADR-156 D-E).
 */
final class EloquentCaseEventRepository extends EloquentRepository implements CaseEventRepository
{
    protected function model(): string
    {
        return CaseEvent::class;
    }

    public function getById(string $id): CaseEvent
    {
        return CaseEvent::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(CaseEvent::class, $id);
    }

    public function getByIdForUpdate(string $id): CaseEvent
    {
        return CaseEvent::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(CaseEvent::class, $id);
    }
}
