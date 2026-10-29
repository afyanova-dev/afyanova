<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\ProcedureDefinitionSummary;
use App\Domains\Clinical\Domain\Model\ProcedureDefinition;
use App\Domains\Clinical\Domain\ProcedureDefinitionReader;
use App\Shared\Domain\NotFound;

final class EloquentProcedureDefinitionReader implements ProcedureDefinitionReader
{
    public function summary(string $definitionId): ProcedureDefinitionSummary
    {
        $definition = ProcedureDefinition::query()->whereKey($definitionId)->first()
            ?? throw NotFound::aggregate(ProcedureDefinition::class, $definitionId);

        return $this->toSummary($definition);
    }

    public function list(?string $category = null, ?bool $active = null): array
    {
        $query = ProcedureDefinition::query();

        if ($category !== null) {
            $query->where('category', $category);
        }

        if ($active !== null) {
            $query->where('active', $active);
        }

        return array_values(
            $query->orderBy('name')->orderBy('id')->get()
                ->map(fn (ProcedureDefinition $definition): ProcedureDefinitionSummary => $this->toSummary($definition))
                ->all()
        );
    }

    private function toSummary(ProcedureDefinition $definition): ProcedureDefinitionSummary
    {
        return new ProcedureDefinitionSummary(
            definitionId: (string) $definition->id,
            code: (string) $definition->code,
            name: (string) $definition->name,
            protocolReference: $definition->protocol_reference,
            category: $definition->category,
            description: $definition->description,
            requiredCapabilityCode: $definition->required_capability_code,
            requiresSite: (bool) $definition->requires_site,
            requiresLaterality: (bool) $definition->requires_laterality,
            requiresReadinessCheck: (bool) $definition->requires_readiness_check,
            materialRequirements: $definition->material_requirements,
            active: (bool) $definition->active,
            version: (int) $definition->version,
        );
    }
}
