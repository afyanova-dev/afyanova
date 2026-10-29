<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\AllergySummary;
use App\Domains\Clinical\Domain\AllergyReader;

/**
 * Orchestrates `GetPatientAllergies` (IC §6.1 / §6.3; ADR-141 D-O / D-N / D-M).
 * No transaction — the QueryBus runs inside the request tenant scope (IC §6.1).
 * No audit, no event, no state mutation.
 *
 * Flow:
 *   1. `AllergyReader::forPatient()` — one patient's allergies in the caller's
 *      tenant, optional `clinical_status` / `verification_status` filter,
 *      deterministically ordered, NO pagination.
 *
 * >>> Q7 (ADR-141 D-M / D-N): TENANT-WIDE. Unlike `GetPatientProblemsHandler`,
 *     this handler computes NO originating-facility whitelist and passes NO
 *     `$inScopeFacilityIds` — a critical allergy is a tenant-wide patient-safety
 *     fact. The coarse `#[ProtectedQuery]` gate (`AllergyPolicy::view` — tenant
 *     + `clinical.allergies.read`) plus tenant RLS IS the authorization. Do NOT
 *     add a `ScopeChecker::selectableFacilities` / `effectivePermissions` filter
 *     here. <<<
 *
 * `substanceName` / `reactionDescription` ARE returned to an authorized caller
 * (the `ProblemSummary` precedent). NO cross-domain call.
 *
 * @return list<AllergySummary>
 */
final class GetPatientAllergiesHandler
{
    public function __construct(
        private readonly AllergyReader $reader,
    ) {}

    /**
     * @return list<AllergySummary>
     */
    public function __invoke(GetPatientAllergies $query): array
    {
        return $this->reader->forPatient(
            $query->patientId,
            $query->clinicalStatus,
            $query->verificationStatus,
        );
    }
}
