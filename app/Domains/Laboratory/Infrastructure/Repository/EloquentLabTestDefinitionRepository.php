<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Repository;

use App\Domains\Laboratory\Domain\LabTestDefinitionRepository;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see LabTestDefinitionRepository} (IC §9;
 * ADR-157 D-C). `LabTestDefinition` emits no domain events (a catalog entry —
 * the CP.1 `ProcedureDefinition` precedent), so `save()` is inherited unchanged.
 */
final class EloquentLabTestDefinitionRepository extends EloquentRepository implements LabTestDefinitionRepository
{
    protected function model(): string
    {
        return LabTestDefinition::class;
    }

    public function getById(string $id): LabTestDefinition
    {
        return LabTestDefinition::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(LabTestDefinition::class, $id);
    }

    public function getByIdForUpdate(string $id): LabTestDefinition
    {
        return LabTestDefinition::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(LabTestDefinition::class, $id);
    }

    public function findActiveByCode(string $tenantId, string $code): ?LabTestDefinition
    {
        return LabTestDefinition::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->where('active', true)
            ->first();
    }
}
