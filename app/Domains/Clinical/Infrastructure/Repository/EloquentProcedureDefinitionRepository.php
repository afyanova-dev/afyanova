<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Repository;

use App\Domains\Clinical\Domain\Model\ProcedureDefinition;
use App\Domains\Clinical\Domain\ProcedureDefinitionRepository;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see ProcedureDefinitionRepository} (IC §9;
 * ADR-146 D-C). `ProcedureDefinition` emits no domain events (no PHASE-6
 * consumer — ADR-129/130), so `save()` is inherited unchanged.
 */
final class EloquentProcedureDefinitionRepository extends EloquentRepository implements ProcedureDefinitionRepository
{
    protected function model(): string
    {
        return ProcedureDefinition::class;
    }

    public function getById(string $id): ProcedureDefinition
    {
        return ProcedureDefinition::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(ProcedureDefinition::class, $id);
    }

    public function getByIdForUpdate(string $id): ProcedureDefinition
    {
        return ProcedureDefinition::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(ProcedureDefinition::class, $id);
    }

    public function findByCode(string $tenantId, string $code): ?ProcedureDefinition
    {
        return ProcedureDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();
    }

    public function save(AggregateRoot $aggregate): void
    {
        $aggregate->save();

        $this->outbox->writeAll($aggregate->releaseEvents());
    }
}
