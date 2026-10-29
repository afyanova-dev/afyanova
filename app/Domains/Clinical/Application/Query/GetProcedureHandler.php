<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Clinical\Domain\ClinicalProcedureReader;
use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetProcedure` (IC §6.1 / §6.3; ADR-146 D-Q). No transaction —
 * the QueryBus runs inside the request tenant scope (IC §6.1). No audit, no
 * event, no state mutation.
 *
 * Flow:
 *   1. `ClinicalProcedureReader::summary()` — RLS-scoped; `NotFound` (404) when
 *      the procedure is missing or in another tenant.
 *   2. facility refinement — the authenticated actor (from `Auth`, never a
 *      query field) must hold `clinical.procedures.read` at THIS procedure's
 *      facility (`ScopeChecker::effectivePermissions`). Otherwise `NotFound`.
 */
final class GetProcedureHandler
{
    public function __construct(
        private readonly ClinicalProcedureReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetProcedure $query): ProcedureSummary
    {
        $summary = $this->reader->summary($query->procedureId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetProcedure dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        if (! in_array(
            'clinical.procedures.read',
            $this->scope->effectivePermissions($user, $summary->facilityId),
            true,
        )) {
            throw NotFound::aggregate(ClinicalProcedure::class, $query->procedureId);
        }

        return $summary;
    }
}
