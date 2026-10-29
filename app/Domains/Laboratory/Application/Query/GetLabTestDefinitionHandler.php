<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabTestDefinitionSummary;
use App\Domains\Laboratory\Domain\LabTestDefinitionReader;

/**
 * Orchestrates `GetLabTestDefinition` (IC §6.1 / §6.3; ADR-157 D-G). No
 * transaction, no audit, no event, no state mutation. The catalog is
 * tenant-wide reference data (RLS-scoped) — the `#[ProtectedQuery]` gate is the
 * authorization (the CP.1 `GetProcedureDefinition` precedent).
 */
final class GetLabTestDefinitionHandler
{
    public function __construct(private readonly LabTestDefinitionReader $reader) {}

    public function __invoke(GetLabTestDefinition $query): LabTestDefinitionSummary
    {
        return $this->reader->summary($query->definitionId);
    }
}
