<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one ProcedureDefinition (ADR-146 D-Q).
 * Returned by `GetProcedureDefinition` / `GetProcedureDefinitions` and consumed
 * intra-domain by `RequestProcedureHandler` / `StartProcedureHandler` to
 * inspect the catalog flags. NOT an event payload. NOT `#[TypeScript]`;
 * workflow controllers project the relevant fields into Inertia props.
 */
final readonly class ProcedureDefinitionSummary
{
    /**
     * @param  list<array{material_ref: string, default_quantity?: float|int|null, unit?: string|null}>|null  $materialRequirements
     */
    public function __construct(
        public string $definitionId,
        public string $code,
        public string $name,
        public ?string $protocolReference,
        public ?string $category,
        public ?string $description,
        public ?string $requiredCapabilityCode,
        public bool $requiresSite,
        public bool $requiresLaterality,
        public bool $requiresReadinessCheck,
        public ?array $materialRequirements,
        public bool $active,
        public int $version,
    ) {}
}
