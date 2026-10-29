<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one procedure's summary by id (13-clinical-procedures-domain.md §164;
 * ADR-146 D-Q). Protected (IC §6.2): a procedure is protected clinical data and
 * facility-scoped (§88, Invariant 24/25).
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.procedures.read`
 * check (`ProcedurePolicy::view`, class-level). `GetProcedureHandler` applies
 * the per-procedure facility refinement: a procedure at a facility outside the
 * caller's scope resolves as `NotFound`, never an authorization disclosure.
 *
 * No side effects: no event, no audit, no state mutation. NOT
 * encounter-state-gated.
 */
#[ProtectedQuery(ability: 'view', subjectType: ClinicalProcedure::class)]
final readonly class GetProcedure
{
    public function __construct(public string $procedureId)
    {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('GetProcedure::$procedureId must be a UUID (IC §3).');
        }
    }
}
