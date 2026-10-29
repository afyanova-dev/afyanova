<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ProcedureDefinitionSummary;
use App\Domains\Clinical\Domain\ProcedureDefinitionReader;

/**
 * Orchestrates `GetProcedureDefinition` (IC §6.1 / §6.3; ADR-146 D-Q). No
 * transaction, no audit, no event, no state mutation. `ProcedureDefinitionReader
 * ::summary()` is RLS-scoped (tenant-wide, not facility-scoped — ADR-146 D-C) —
 * `NotFound` (404) when the definition is missing or in another tenant. No
 * further refinement is needed.
 */
final class GetProcedureDefinitionHandler
{
    public function __construct(private readonly ProcedureDefinitionReader $reader) {}

    public function __invoke(GetProcedureDefinition $query): ProcedureDefinitionSummary
    {
        return $this->reader->summary($query->definitionId);
    }
}
