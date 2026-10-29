<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Repository;

use App\Domains\Clinical\Domain\ClinicalPlanRepository;
use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentClinicalPlanRepository extends EloquentRepository implements ClinicalPlanRepository
{
    protected function model(): string
    {
        return ClinicalPlan::class;
    }

    public function getById(string $id): ClinicalPlan
    {
        return ClinicalPlan::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(ClinicalPlan::class, $id);
    }

    public function getByIdForUpdate(string $id): ClinicalPlan
    {
        return ClinicalPlan::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(ClinicalPlan::class, $id);
    }

    public function save(AggregateRoot $aggregate): void
    {
        $aggregate->save();

        if ($aggregate instanceof ClinicalPlan) {
            foreach ($aggregate->releasePendingHistory() as $historyEntry) {
                $historyEntry->save();
            }
        }

        $this->outbox->writeAll($aggregate->releaseEvents());
    }
}
