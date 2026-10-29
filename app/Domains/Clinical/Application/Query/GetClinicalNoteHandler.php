<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\ClinicalNoteSummary;
use App\Domains\Clinical\Domain\ClinicalNoteReader;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `GetClinicalNote` (IC §6.1 / §6.3; ADR-133, ADR-134). No
 * transaction — the QueryBus runs inside the request tenant scope (IC §6.1). No
 * audit, no event, no state mutation.
 *
 * Flow:
 *   1. `ClinicalNoteReader::summary()` — RLS-scoped; `NotFound` (404) when the
 *      note is missing or in another tenant.
 *   2. ADR-133 facility refinement — the authenticated actor (from `Auth`,
 *      never a query field) must hold `clinical.notes.read` at THIS note's
 *      facility (`ScopeChecker::effectivePermissions`). Otherwise `NotFound` —
 *      §88 / Invariant 24/25: facility selection does not grant clinical-record
 *      access; an out-of-scope note is indistinguishable from an absent one.
 *
 * The `body` IS returned to an authorized caller — the `#[ProtectedQuery]` gate
 * plus this refinement IS the authorization (the `PatientSummary::$dateOfBirth`
 * precedent). NO cross-domain call — the DTO carries `patientId` / `encounterId`,
 * not resolved patient / encounter detail (PHASE 16 concern).
 */
final class GetClinicalNoteHandler
{
    public function __construct(
        private readonly ClinicalNoteReader $reader,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(GetClinicalNote $query): ClinicalNoteSummary
    {
        $summary = $this->reader->summary($query->clinicalNoteId);

        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('GetClinicalNote dispatched without an authenticated user (IC §6.1/§6.2).');
        }

        if (! in_array(
            'clinical.notes.read',
            $this->scope->effectivePermissions($user, $summary->facilityId),
            true,
        )) {
            throw NotFound::aggregate(ClinicalNote::class, $query->clinicalNoteId);
        }

        return $summary;
    }
}
