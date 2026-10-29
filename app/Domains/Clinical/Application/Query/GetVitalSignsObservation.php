<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one vitals observation's summary by id (12-clinical-domain.md §164;
 * ADR-138 D15). Protected (IC §6.2): vital-sign values are protected clinical
 * data and facility-scoped (§88, Invariant 24/25).
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.vitals.read`
 * check (`VitalSignsObservationPolicy::view`, class-level — the QueryBus never
 * loads a subject). `GetVitalSignsObservationHandler` applies the
 * per-observation facility refinement (ADR-133): an observation at a facility
 * outside the caller's scope resolves as `NotFound`, never an authorization
 * disclosure.
 *
 * The ONLY C.2 query — no latest / trend / encounter-vitals reads and no
 * `clin_rm_*` projection (PHASE 16). No side effects: no event, no audit, no
 * state mutation.
 */
#[ProtectedQuery(ability: 'view', subjectType: VitalSignsObservation::class)]
final readonly class GetVitalSignsObservation
{
    public function __construct(public string $vitalSignsObservationId)
    {
        if (! Str::isUuid($vitalSignsObservationId)) {
            throw new InvalidArgumentException('GetVitalSignsObservation::$vitalSignsObservationId must be a UUID (IC §3).');
        }
    }
}
