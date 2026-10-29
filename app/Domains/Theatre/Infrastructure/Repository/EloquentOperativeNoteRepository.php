<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Repository;

use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Domains\Theatre\Domain\Model\OperativeNoteAmendment;
use App\Domains\Theatre\Domain\OperativeNoteRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of OperativeNoteRepository (ADR-152 D-C).
 */
final class EloquentOperativeNoteRepository extends EloquentRepository implements OperativeNoteRepository
{
    protected function model(): string
    {
        return OperativeNote::class;
    }

    public function getById(string $id): OperativeNote
    {
        return OperativeNote::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(OperativeNote::class, $id);
    }

    public function getByIdForUpdate(string $id): OperativeNote
    {
        return OperativeNote::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(OperativeNote::class, $id);
    }

    public function nextAmendmentSequence(string $operativeNoteId): int
    {
        $max = OperativeNoteAmendment::query()
            ->where('operative_note_id', $operativeNoteId)
            ->max('sequence');

        return ((int) $max) + 1;
    }
}
