<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one procedure catalog entry by id (13-clinical-procedures-domain.md
 * §12; ADR-146 D-Q). Protected (IC §6.2) at the coarse `clinical.procedures.read`
 * key — the catalog is tenant-wide reference data, not facility-scoped
 * (ADR-146 D-C), so there is no further per-record refinement (unlike
 * `GetProcedure`).
 *
 * Routed via `ProcedurePolicy::view` (the same class-level check as
 * `GetProcedure` / `GetEncounterProcedures` / `GetPatientProcedures` —
 * `subjectType` is `ClinicalProcedure::class` because the QueryBus routes
 * `#[ProtectedQuery]` through ONE registered `Gate::policy` per module
 * concern; `ProcedureDefinition` has no dedicated policy in CP.1).
 */
#[ProtectedQuery(ability: 'view', subjectType: ClinicalProcedure::class)]
final readonly class GetProcedureDefinition
{
    public function __construct(public string $definitionId)
    {
        if (! Str::isUuid($definitionId)) {
            throw new InvalidArgumentException('GetProcedureDefinition::$definitionId must be a UUID (IC §3).');
        }
    }
}
