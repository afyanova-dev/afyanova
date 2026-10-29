<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Contracts\Dto\ProcedureDefinitionSummary;

/**
 * Read-side port for ProcedureDefinition (IC §6.2 / §6.3; ADR-146 D-Q).
 * Implementation: `Infrastructure/Reader/EloquentProcedureDefinitionReader`.
 * Returns DTOs only — never an Eloquent model (IC §9).
 *
 * `summary` backs `GetProcedureDefinition` (by-id) and is also the INTRA-domain
 * read `RequestProcedureHandler` uses to inspect the definition's capability /
 * site / laterality / readiness flags (ADR-146 D-G). `list` backs
 * `GetProcedureDefinitions` (the catalog, filterable by category / active — a
 * consumer needs it to populate a picker).
 */
interface ProcedureDefinitionReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the definition does not exist in
     * the current tenant scope (RLS).
     */
    public function summary(string $definitionId): ProcedureDefinitionSummary;

    /**
     * The tenant's catalog, optionally filtered. NO pagination — catalogs are
     * small (ADR-146 D-Q).
     *
     * @return list<ProcedureDefinitionSummary>
     */
    public function list(?string $category = null, ?bool $active = null): array;
}
