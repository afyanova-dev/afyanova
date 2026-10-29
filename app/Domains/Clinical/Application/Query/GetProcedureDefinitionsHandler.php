<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ProcedureDefinitionSummary;
use App\Domains\Clinical\Domain\ProcedureDefinitionReader;

/**
 * Orchestrates `GetProcedureDefinitions` (IC §6.1 / §6.3; ADR-146 D-Q). No
 * transaction, no audit, no event, no state mutation.
 */
final class GetProcedureDefinitionsHandler
{
    public function __construct(private readonly ProcedureDefinitionReader $reader) {}

    /**
     * @return list<ProcedureDefinitionSummary>
     */
    public function __invoke(GetProcedureDefinitions $query): array
    {
        return $this->reader->list($query->category, $query->active);
    }
}
