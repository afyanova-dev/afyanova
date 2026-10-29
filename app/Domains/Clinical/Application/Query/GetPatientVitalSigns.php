<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * List one patient's vitals observations, newest first (12-clinical-domain.md
 * §164). Protected (IC §6.2): vitals are protected clinical data (§88,
 * Invariant 24/25) — the coarse tenant + `clinical.vitals.read` check
 * (`VitalSignsObservationPolicy::view`, class-level) plus the handler's
 * per-facility refinement (ADR-133, the `GetPatientProblems` precedent).
 *
 * Bounded deterministic list, NO pagination — one patient's observations,
 * newest first. Read-only: no event, no audit, no state mutation.
 */
#[ProtectedQuery(ability: 'view', subjectType: VitalSignsObservation::class)]
final readonly class GetPatientVitalSigns
{
    public function __construct(public string $patientId)
    {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientVitalSigns::$patientId must be a UUID (IC §3).');
        }
    }
}
