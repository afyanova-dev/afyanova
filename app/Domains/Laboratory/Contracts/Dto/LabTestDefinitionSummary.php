<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one laboratory test catalog entry
 * (ADR-157 D-G). Returned by `GetLabTestDefinition` / `GetLabTestDefinitions`
 * and consumed intra-domain by `CreateLabOrderHandler` to snapshot the code /
 * name / version and inspect the availability / capability flags. NOT an event
 * payload.
 */
final readonly class LabTestDefinitionSummary
{
    /**
     * @param  list<array{code: string, name?: string|null, unit?: string|null}>|null  $parameters
     */
    public function __construct(
        public string $definitionId,
        public string $code,
        public string $name,
        public ?string $category,
        public ?string $description,
        public ?string $specimenType,
        public ?string $containerType,
        public ?array $parameters,
        public ?string $requiredCapabilityCode,
        public string $availabilityStatus,
        public ?int $turnaroundTargetMinutes,
        public bool $active,
        public int $version,
    ) {}
}
