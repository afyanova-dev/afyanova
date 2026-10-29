<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one triage assessment's summary by id (12-clinical-domain.md §164;
 * ADR-145 D-H). Protected (IC §6.2): a triage assessment is protected clinical
 * data — its chief complaint is clinical detail and it is facility-scoped
 * (§88, Invariant 24/25).
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.triage.read`
 * check (`TriageAssessmentPolicy::view`, class-level — the QueryBus never loads
 * a subject). `GetTriageAssessmentHandler` applies the per-assessment facility
 * refinement (ADR-145): an assessment at a facility outside the caller's scope
 * resolves as `NotFound`, never an authorization disclosure.
 *
 * One of the two C.9 queries. No side effects: no event, no audit, no state
 * mutation. NOT encounter-state-gated.
 */
#[ProtectedQuery(ability: 'view', subjectType: TriageAssessment::class)]
final readonly class GetTriageAssessment
{
    public function __construct(public string $triageAssessmentId)
    {
        if (! Str::isUuid($triageAssessmentId)) {
            throw new InvalidArgumentException('GetTriageAssessment::$triageAssessmentId must be a UUID (IC §3).');
        }
    }
}
