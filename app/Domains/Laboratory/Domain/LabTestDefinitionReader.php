<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Contracts\Dto\LabTestDefinitionSummary;

/**
 * Read-side port for LabTestDefinition (IC §6.2 / §6.3; ADR-157 D-G).
 * Implementation: `Infrastructure/Reader/EloquentLabTestDefinitionReader`.
 * Returns DTOs only — never an Eloquent model (IC §9).
 *
 * `summary` backs `GetLabTestDefinition` (by-id) and is also the INTRA-domain
 * read `CreateLabOrderHandler` uses to snapshot the code / name / version and
 * inspect the availability / capability flags. `list` backs
 * `GetLabTestDefinitions` (the catalog, filterable by category / active — a
 * consumer needs it to populate an order picker — the CP.1 precedent).
 */
interface LabTestDefinitionReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the definition does not exist in
     * the current tenant scope (RLS).
     */
    public function summary(string $definitionId): LabTestDefinitionSummary;

    /**
     * The tenant's catalog, optionally filtered. NO pagination — catalogs are
     * small (the CP.1 precedent).
     *
     * @return list<LabTestDefinitionSummary>
     */
    public function list(?string $category = null, ?bool $active = null): array;
}
