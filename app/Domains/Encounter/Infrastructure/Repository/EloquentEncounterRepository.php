<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Infrastructure\Repository;

use App\Domains\Encounter\Domain\EncounterRepository;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see EncounterRepository} (IMPLEMENTATION-CONTRACTS.md
 * §9). `save` (persist + drain events to the outbox — one transaction, the
 * bus's) is inherited from EloquentRepository unchanged; the Encounter
 * aggregate has no child entities to persist alongside the root in PHASE 5.
 * getById / getByIdForUpdate are overridden only to narrow the return type.
 */
final class EloquentEncounterRepository extends EloquentRepository implements EncounterRepository
{
    protected function model(): string
    {
        return Encounter::class;
    }

    public function getById(string $id): Encounter
    {
        return Encounter::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Encounter::class, $id);
    }

    public function getByIdForUpdate(string $id): Encounter
    {
        return Encounter::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Encounter::class, $id);
    }
}
