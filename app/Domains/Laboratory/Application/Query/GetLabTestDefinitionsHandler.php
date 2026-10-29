<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Contracts\Dto\LabTestDefinitionSummary;
use App\Domains\Laboratory\Domain\LabTestDefinitionReader;

/**
 * Orchestrates `GetLabTestDefinitions` (IC §6.1 / §6.3; ADR-157 D-G). No
 * transaction, no audit, no event, no state mutation.
 */
final class GetLabTestDefinitionsHandler
{
    public function __construct(private readonly LabTestDefinitionReader $reader) {}

    /**
     * @return list<LabTestDefinitionSummary>
     */
    public function __invoke(GetLabTestDefinitions $query): array
    {
        return $this->reader->list($query->category, $query->active);
    }
}
