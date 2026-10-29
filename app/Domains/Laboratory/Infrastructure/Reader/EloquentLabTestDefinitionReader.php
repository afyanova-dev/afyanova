<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Reader;

use App\Domains\Laboratory\Contracts\Dto\LabTestDefinitionSummary;
use App\Domains\Laboratory\Domain\LabTestDefinitionReader;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Shared\Domain\NotFound;

final class EloquentLabTestDefinitionReader implements LabTestDefinitionReader
{
    public function summary(string $definitionId): LabTestDefinitionSummary
    {
        $definition = LabTestDefinition::query()->whereKey($definitionId)->first()
            ?? throw NotFound::aggregate(LabTestDefinition::class, $definitionId);

        return $this->toSummary($definition);
    }

    public function list(?string $category = null, ?bool $active = null): array
    {
        $query = LabTestDefinition::query();

        if ($category !== null) {
            $query->where('category', $category);
        }

        if ($active !== null) {
            $query->where('active', $active);
        }

        return array_values(
            $query->orderBy('name')->orderBy('id')->get()
                ->map(fn (LabTestDefinition $definition): LabTestDefinitionSummary => $this->toSummary($definition))
                ->all()
        );
    }

    private function toSummary(LabTestDefinition $definition): LabTestDefinitionSummary
    {
        return new LabTestDefinitionSummary(
            definitionId: (string) $definition->id,
            code: (string) $definition->code,
            name: (string) $definition->name,
            category: $definition->category,
            description: $definition->description,
            specimenType: $definition->specimen_type,
            containerType: $definition->container_type,
            parameters: $definition->parameters,
            requiredCapabilityCode: $definition->required_capability_code,
            availabilityStatus: (string) $definition->availability_status,
            turnaroundTargetMinutes: $definition->turnaround_target_minutes,
            active: (bool) $definition->active,
            version: (int) $definition->version,
        );
    }
}
