<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Authorization;

use App\Domains\Clinical\Application\Command\AddProblem;
use App\Domains\Clinical\Application\Command\ResolveProblem;
use App\Domains\Clinical\Application\Command\UpdateProblem;
use App\Domains\Clinical\Domain\Model\Problem;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Problem aggregate policy (IC §12; 12-clinical-domain.md §87–§90, §159;
 * ADR-133, ADR-140 D-K). The exact `DiagnosisPolicy` /
 * `VitalSignsObservationPolicy` shape:
 *
 *   - class-level checks (`record`, `view`) use `permits()` — the key in the
 *     actor's CURRENT facility context;
 *   - the per-subject checks (`update`, `resolve`) evaluate the key against the
 *     PROBLEM's ORIGINATING facility via `ScopeChecker::effectivePermissions`,
 *     because `clin_problems` RLS is tenant-scoped (§88, Invariant 24/25;
 *     ADR-140 D-B — originating-facility-only visibility).
 *
 * The handlers add a further ADR-140 refinement where the class-level check
 * cannot reach the target facility (`AddProblem` against the encounter's
 * facility; `GetProblem` against the problem's facility).
 *
 * Exactly two keys (ADR-140 D-K): `clinical.problems.read` and
 * `clinical.problems.record`. **`record` authorizes Add, Update AND Resolve** —
 * there is no `clinical.problems.manage` / `.update` / `.correct` / `.resolve` /
 * `.remove` / `.promote` / `.amend` key. Care-team membership is NOT an
 * authorization input (`12` §159). Break-glass / sensitivity remain DEFERRED
 * (ADR-133).
 */
final class ProblemPolicy extends BasePolicy
{
    /**
     * AddProblem — class-level (create) check; there is no subject yet
     * (IC §5.1). `permits()` resolves `clinical.problems.record` in the actor's
     * current facility context; `AddProblemHandler` adds the
     * per-encounter-facility refinement (ADR-140 D-K).
     */
    public function record(User $user, ?AddProblem $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.problems.record');
    }

    /**
     * GetProblem / GetPatientProblems (`#[ProtectedQuery]`, IC §6.2) —
     * class-level check; the QueryBus never loads a subject. Coarse tenant +
     * `clinical.problems.read`. The per-problem originating-facility refinement
     * is the handler's (ADR-140 D-K / D-L).
     */
    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'clinical.problems.read');
    }

    /**
     * UpdateProblem — per-subject check; the bus loaded the problem being
     * revised / corrected from its (RLS-scoped) repository. Gated by the
     * **`clinical.problems.record`** key (ADR-140 D-K — the same key as adding;
     * a revision / correction is still `record`). Evaluated against the
     * PROBLEM's ORIGINATING facility (§88, Invariant 24/25; ADR-140 D-B).
     */
    public function update(User $user, Problem $problem, ?UpdateProblem $command = null): bool
    {
        return $this->recordAtOriginatingFacility($user, $problem);
    }

    /**
     * ResolveProblem — per-subject check; identical gate to {@see update()}
     * (ADR-140 D-K — `record` authorizes Resolve; there is no
     * `clinical.problems.resolve` key).
     */
    public function resolve(User $user, Problem $problem, ?ResolveProblem $command = null): bool
    {
        return $this->recordAtOriginatingFacility($user, $problem);
    }

    private function recordAtOriginatingFacility(User $user, Problem $problem): bool
    {
        return $this->sameTenant($user, (string) $problem->tenant_id)
            && in_array(
                'clinical.problems.record',
                $this->scope->effectivePermissions($user, (string) $problem->facility_id),
                true,
            );
    }
}
