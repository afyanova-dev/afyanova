<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Repository;

use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see ClinicalProcedureRepository} (IC §9;
 * ADR-146).
 *
 * `save()` is overridden to persist the procedure's append-only
 * `clin_procedure_amendments` / `clin_procedure_materials` child rows alongside
 * the root — the `ClinicalNote` / `ClinicalNoteAmendment` extension point
 * (ADR-134). Everything happens inside the bus's single transaction: the
 * versioned procedure write, then each new child row, then the drain of
 * released domain events to the outbox.
 *
 * `getById` / `getByIdForUpdate` are overridden only to narrow the return type.
 */
final class EloquentClinicalProcedureRepository extends EloquentRepository implements ClinicalProcedureRepository
{
    protected function model(): string
    {
        return ClinicalProcedure::class;
    }

    public function getById(string $id): ClinicalProcedure
    {
        return ClinicalProcedure::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(ClinicalProcedure::class, $id);
    }

    public function getByIdForUpdate(string $id): ClinicalProcedure
    {
        return ClinicalProcedure::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(ClinicalProcedure::class, $id);
    }

    public function save(AggregateRoot $aggregate): void
    {
        $aggregate->save();

        if ($aggregate instanceof ClinicalProcedure) {
            foreach ($aggregate->releasePendingAmendments() as $amendment) {
                $amendment->save();
            }

            foreach ($aggregate->releasePendingMaterials() as $material) {
                $material->save();
            }
        }

        $this->outbox->writeAll($aggregate->releaseEvents());
    }
}
