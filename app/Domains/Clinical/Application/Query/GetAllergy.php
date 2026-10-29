<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\Allergy;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one allergy assertion's summary by id (12-clinical-domain.md §164;
 * ADR-141 D-O). Protected (IC §6.2): an allergy is protected clinical data —
 * its substance name and reaction text are clinical detail.
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.allergies.read`
 * check (`AllergyPolicy::view`, class-level — the QueryBus never loads a
 * subject).
 *
 * >>> Q7 (ADR-141 D-M): `GetAllergyHandler` does NO originating-facility
 *     refinement — allergy READS are TENANT-WIDE (a critical allergy is a
 *     tenant-wide safety fact). This is the deliberate difference from
 *     `GetDiagnosis` / `GetProblem`, which DO refine. Do not add a refinement
 *     step. <<<
 *
 * One of exactly two C.5 read capabilities (with `GetPatientAllergies`) — no
 * history / timeline reads and no `clin_rm_*` projection (deferred). No side
 * effects: no event, no audit, no state mutation. NOT encounter-state-gated.
 */
#[ProtectedQuery(ability: 'view', subjectType: Allergy::class)]
final readonly class GetAllergy
{
    public function __construct(public string $allergyId)
    {
        if (! Str::isUuid($allergyId)) {
            throw new InvalidArgumentException('GetAllergy::$allergyId must be a UUID (IC §3).');
        }
    }
}
