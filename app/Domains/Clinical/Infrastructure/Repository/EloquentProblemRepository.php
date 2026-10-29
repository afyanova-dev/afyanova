<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Repository;

use App\Domains\Clinical\Domain\Model\Problem;
use App\Domains\Clinical\Domain\ProblemRepository;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see ProblemRepository} (IC §9; ADR-140).
 *
 * `save()` is overridden to persist the problem's append-only
 * `clin_problem_history` child rows alongside the root — the same extension
 * point `EloquentClinicalNoteRepository` uses for `clin_note_amendments`
 * (ADR-134). Everything happens inside the bus's single transaction: the
 * versioned `clin_problems` write, then each new pre-change history row, then
 * the drain of released domain events (`ProblemAdded` / `ProblemUpdated` /
 * `ProblemResolved`) to the outbox.
 *
 * `getById` / `getByIdForUpdate` are overridden only to narrow the return type.
 */
final class EloquentProblemRepository extends EloquentRepository implements ProblemRepository
{
    protected function model(): string
    {
        return Problem::class;
    }

    public function getById(string $id): Problem
    {
        return Problem::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Problem::class, $id);
    }

    public function getByIdForUpdate(string $id): Problem
    {
        return Problem::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Problem::class, $id);
    }

    public function save(AggregateRoot $aggregate): void
    {
        $aggregate->save(); // versioned INSERT / UPDATE of clin_problems

        if ($aggregate instanceof Problem) {
            foreach ($aggregate->releasePendingHistory() as $historyEntry) {
                $historyEntry->save(); // append-only clin_problem_history row
            }
        }

        $this->outbox->writeAll($aggregate->releaseEvents());
    }
}
