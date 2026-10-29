<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Repository;

use App\Domains\Clinical\Domain\ClinicalNoteRepository;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see ClinicalNoteRepository} (IC §9; ADR-134).
 *
 * `save()` is overridden to persist the note's append-only
 * `clin_note_amendments` child rows alongside the root — the same extension
 * point `EloquentPatientRepository` / `PatientIdentityHistory` use (ADR-128).
 * Everything happens inside the bus's single transaction: the versioned note
 * write, then each new amendment row, then the drain of released domain events
 * to the outbox.
 *
 * `getById` / `getByIdForUpdate` are overridden only to narrow the return type.
 */
final class EloquentClinicalNoteRepository extends EloquentRepository implements ClinicalNoteRepository
{
    protected function model(): string
    {
        return ClinicalNote::class;
    }

    public function getById(string $id): ClinicalNote
    {
        return ClinicalNote::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(ClinicalNote::class, $id);
    }

    public function getByIdForUpdate(string $id): ClinicalNote
    {
        return ClinicalNote::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(ClinicalNote::class, $id);
    }

    public function save(AggregateRoot $aggregate): void
    {
        $aggregate->save();

        if ($aggregate instanceof ClinicalNote) {
            foreach ($aggregate->releasePendingAmendments() as $amendment) {
                $amendment->save();
            }
        }

        $this->outbox->writeAll($aggregate->releaseEvents());
    }
}
