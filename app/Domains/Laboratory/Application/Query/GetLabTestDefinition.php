<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one laboratory test catalog entry by id (14-laboratory-domain.md §12;
 * ADR-157 D-G). Protected at the coarse `laboratory.access` key (the CP.1
 * `GetProcedureDefinition` precedent — the catalog reads route through the
 * order policy, there is no separate catalog policy in Lab.1).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabOrder::class)]
final readonly class GetLabTestDefinition
{
    public function __construct(public string $definitionId)
    {
        if (! Str::isUuid($definitionId)) {
            throw new InvalidArgumentException('GetLabTestDefinition::$definitionId must be a UUID (IC §3).');
        }
    }
}
