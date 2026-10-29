<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\Problem;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one longitudinal problem's summary by id (12-clinical-domain.md §164;
 * ADR-140 D-L). Protected (IC §6.2): a problem is protected clinical data — its
 * code and description are clinical detail and it is originating-facility-scoped
 * (ADR-140 D-B / D-K).
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.problems.read`
 * check (`ProblemPolicy::view`, class-level — the QueryBus never loads a
 * subject). `GetProblemHandler` applies the per-problem ORIGINATING-facility
 * refinement (ADR-140 D-K): a problem whose originating facility is outside the
 * caller's scope resolves as `NotFound`, never an authorization disclosure.
 *
 * One of exactly two C.4 read capabilities (with `GetPatientProblems`) — no
 * history / timeline / `ActiveProblemsView` reads and no `clin_rm_*` projection
 * (PHASE 16). No side effects: no event, no audit, no state mutation. NOT
 * encounter-state-gated.
 */
#[ProtectedQuery(ability: 'view', subjectType: Problem::class)]
final readonly class GetProblem
{
    public function __construct(public string $problemId)
    {
        if (! Str::isUuid($problemId)) {
            throw new InvalidArgumentException('GetProblem::$problemId must be a UUID (IC §3).');
        }
    }
}
