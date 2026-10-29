<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ProblemSummary;
use App\Domains\Clinical\Domain\Model\Problem;
use App\Domains\Clinical\Domain\ProblemReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetProblem` (IC §6.1 / §6.3; ADR-140 D-L). No transaction — the
 * QueryBus runs inside the request tenant scope (IC §6.1). No audit, no event,
 * no state mutation.
 *
 * Flow:
 *   1. `ProblemReader::summary()` — RLS-scoped; `NotFound` (404) when the
 *      problem is missing or in another tenant.
 *   2. ADR-140 D-K originating-facility refinement — the authenticated actor
 *      (from `Auth`, never a query field) must hold `clinical.problems.read` at
 *      THIS problem's ORIGINATING facility (`ScopeChecker::effectivePermissions`).
 *      Otherwise `NotFound` — originating-facility-only visibility (ADR-140
 *      D-B); an out-of-scope problem is indistinguishable from an absent one.
 *
 * The `code` and `description` ARE returned to an authorized caller — the
 * `#[ProtectedQuery]` gate plus this refinement IS the authorization (the
 * E.12 / `DiagnosisSummary` precedent). NO cross-domain call.
 */
final class GetProblemHandler
{
    public function __construct(
        private readonly ProblemReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetProblem $query): ProblemSummary
    {
        $summary = $this->reader->summary($query->problemId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetProblem dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        if (! in_array(
            'clinical.problems.read',
            $this->scope->effectivePermissions($user, $summary->facilityId),
            true,
        )) {
            throw NotFound::aggregate(Problem::class, $query->problemId);
        }

        return $summary;
    }
}
