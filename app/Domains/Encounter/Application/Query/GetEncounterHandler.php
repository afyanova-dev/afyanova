<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Query;

use App\Domains\Encounter\Contracts\Dto\EncounterSummary;
use App\Domains\Encounter\Domain\EncounterReader;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetEncounter` (IC §6.1 / §6.3; ADR-130; E.12). No transaction —
 * the QueryBus runs inside the request tenant scope (IC §6.1). No audit (a
 * single authorized by-id read — R-3), no event, no state mutation.
 *
 * Flow:
 *   1. `EncounterReader::summary()` — RLS-scoped; `NotFound` (404) when the
 *      encounter is missing or in another tenant.
 *   2. R-1 facility refinement — the authenticated actor (from `Auth`, never a
 *      query field, so it cannot be spoofed: the `SearchPatients` precedent)
 *      must hold `encounter.encounters.read` at THIS encounter's facility
 *      (`ScopeChecker::effectivePermissions`). Otherwise `NotFound` — §114 /
 *      Invariant 24/25: "facility selection does not grant encounter access";
 *      an out-of-scope encounter is indistinguishable from an absent one, never
 *      an authorization disclosure.
 *
 * NO `PatientApi` / Clinical / Queueing / Bed & Ward / Billing / Care
 * Coordination / Scheduling call — the DTO carries `patientId`, not a resolved
 * patient name (§98; that is a PHASE 16 composed-read-model concern).
 */
final class GetEncounterHandler
{
    public function __construct(
        private readonly EncounterReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetEncounter $query): EncounterSummary
    {
        $summary = $this->reader->summary($query->encounterId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetEncounter dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        if (! in_array(
            'encounter.encounters.read',
            $this->scope->effectivePermissions($user, $summary->facilityId),
            true,
        )) {
            throw NotFound::aggregate(Encounter::class, $query->encounterId);
        }

        return $summary;
    }
}
