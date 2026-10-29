<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Repository;

use App\Domains\Clinical\Domain\AllergyRepository;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see AllergyRepository} (IC §9; ADR-141).
 *
 * `save()` is overridden to persist the allergy's append-only
 * `clin_allergy_history` child rows alongside the root — the same extension
 * point `EloquentProblemRepository` uses for `clin_problem_history` (ADR-140).
 * Everything happens inside the bus's single transaction: the versioned
 * `clin_allergies` write, then each new pre-change history row
 * (`UpdateAllergy` / `VerifyAllergy` — `ResolveAllergy` writes none, ADR-141
 * D-J), then the drain of released domain events (`AllergyRecorded` /
 * `AllergyUpdated` / `AllergyVerified` / `AllergyResolved`) to the outbox.
 *
 * `getById` / `getByIdForUpdate` are overridden only to narrow the return type.
 */
final class EloquentAllergyRepository extends EloquentRepository implements AllergyRepository
{
    protected function model(): string
    {
        return Allergy::class;
    }

    public function getById(string $id): Allergy
    {
        return Allergy::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Allergy::class, $id);
    }

    public function getByIdForUpdate(string $id): Allergy
    {
        return Allergy::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Allergy::class, $id);
    }

    public function save(AggregateRoot $aggregate): void
    {
        $aggregate->save(); // versioned INSERT / UPDATE of clin_allergies

        if ($aggregate instanceof Allergy) {
            foreach ($aggregate->releasePendingHistory() as $historyEntry) {
                $historyEntry->save(); // append-only clin_allergy_history row
            }
        }

        $this->outbox->writeAll($aggregate->releaseEvents());
    }
}
