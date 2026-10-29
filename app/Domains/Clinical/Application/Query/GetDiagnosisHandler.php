<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\DiagnosisSummary;
use App\Domains\Clinical\Domain\DiagnosisReader;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetDiagnosis` (IC §6.1 / §6.3; ADR-139 D12). No transaction —
 * the QueryBus runs inside the request tenant scope (IC §6.1). No audit, no
 * event, no state mutation.
 *
 * Flow:
 *   1. `DiagnosisReader::summary()` — RLS-scoped; `NotFound` (404) when the
 *      diagnosis is missing or in another tenant.
 *   2. ADR-139 facility refinement — the authenticated actor (from `Auth`,
 *      never a query field) must hold `clinical.diagnoses.read` at THIS
 *      diagnosis's facility (`ScopeChecker::effectivePermissions`). Otherwise
 *      `NotFound` — §88 / Invariant 24/25: facility selection does not grant
 *      clinical-record access; an out-of-scope diagnosis is indistinguishable
 *      from an absent one.
 *
 * The `code` and `description` ARE returned to an authorized caller — the
 * `#[ProtectedQuery]` gate plus this refinement IS the authorization (the
 * E.12 / `PatientSummary::$dateOfBirth` precedent). NO cross-domain call.
 */
final class GetDiagnosisHandler
{
    public function __construct(
        private readonly DiagnosisReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetDiagnosis $query): DiagnosisSummary
    {
        $summary = $this->reader->summary($query->diagnosisId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetDiagnosis dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        if (! in_array(
            'clinical.diagnoses.read',
            $this->scope->effectivePermissions($user, $summary->facilityId),
            true,
        )) {
            throw NotFound::aggregate(Diagnosis::class, $query->diagnosisId);
        }

        return $summary;
    }
}
