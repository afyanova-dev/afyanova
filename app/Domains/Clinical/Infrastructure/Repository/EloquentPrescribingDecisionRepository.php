<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Repository;

use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Domains\Clinical\Domain\PrescribingDecisionRepository;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentPrescribingDecisionRepository extends EloquentRepository implements PrescribingDecisionRepository
{
    protected function model(): string
    {
        return PrescribingDecision::class;
    }

    public function getById(string $id): PrescribingDecision
    {
        return PrescribingDecision::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(PrescribingDecision::class, $id);
    }

    public function getByIdForUpdate(string $id): PrescribingDecision
    {
        return PrescribingDecision::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(PrescribingDecision::class, $id);
    }

    public function save(AggregateRoot $aggregate): void
    {
        $aggregate->save();

        $this->outbox->writeAll($aggregate->releaseEvents());
    }

    public function saveCorrection(PrescribingDecision $original, PrescribingDecision $correction): void
    {
        $correction->save();
        $original->save();

        $this->outbox->writeAll($correction->releaseEvents());
        $this->outbox->writeAll($original->releaseEvents());
    }
}
