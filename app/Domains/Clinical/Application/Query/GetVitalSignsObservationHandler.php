<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\VitalSignsObservationSummary;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\VitalSignsObservationReader;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetVitalSignsObservation` (IC §6.1 / §6.3; ADR-133, ADR-138).
 * No transaction — the QueryBus runs inside the request tenant scope (IC §6.1).
 * No audit, no event, no state mutation.
 *
 * Flow:
 *   1. `VitalSignsObservationReader::summary()` — RLS-scoped; `NotFound` (404)
 *      when the observation is missing or in another tenant.
 *   2. ADR-133 facility refinement — the authenticated actor (from `Auth`, never
 *      a query field) must hold `clinical.vitals.read` at THIS observation's
 *      facility (`ScopeChecker::effectivePermissions`). Otherwise `NotFound` —
 *      §88 / Invariant 24/25: facility selection does not grant clinical-record
 *      access; an out-of-scope observation is indistinguishable from an absent
 *      one.
 *
 * The measurement values ARE returned to an authorized caller — the
 * `#[ProtectedQuery]` gate plus this refinement IS the authorization (the E.12 /
 * `PatientSummary::$dateOfBirth` precedent). NO cross-domain call.
 */
final class GetVitalSignsObservationHandler
{
    public function __construct(
        private readonly VitalSignsObservationReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetVitalSignsObservation $query): VitalSignsObservationSummary
    {
        $summary = $this->reader->summary($query->vitalSignsObservationId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetVitalSignsObservation dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        if (! in_array(
            'clinical.vitals.read',
            $this->scope->effectivePermissions($user, $summary->facilityId),
            true,
        )) {
            throw NotFound::aggregate(VitalSignsObservation::class, $query->vitalSignsObservationId);
        }

        return $summary;
    }
}
