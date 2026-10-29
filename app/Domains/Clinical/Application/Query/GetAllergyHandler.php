<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Contracts\Dto\AllergySummary;
use App\Domains\Clinical\Domain\AllergyReader;

/**
 * Orchestrates `GetAllergy` (IC §6.1 / §6.3; ADR-141 D-O / D-M). No transaction
 * — the QueryBus runs inside the request tenant scope (IC §6.1). No audit, no
 * event, no state mutation.
 *
 * Flow:
 *   1. `AllergyReader::summary()` — RLS-scoped; `NotFound` (404) when the
 *      allergy is missing or in another tenant.
 *
 * >>> That is the WHOLE flow. Unlike `GetDiagnosisHandler` /
 *     `GetProblemHandler`, there is NO step 2 originating-facility refinement:
 *     allergy READS are TENANT-WIDE within the tenant (ADR-141 D-M — the Q7
 *     patient-safety exception). The `#[ProtectedQuery]` gate on `GetAllergy`
 *     (`AllergyPolicy::view` — tenant + `clinical.allergies.read`) plus tenant
 *     RLS IS the authorization. Do NOT add a `ScopeChecker::effectivePermissions`
 *     check here — it would silently re-impose facility scoping and break Q7. <<<
 *
 * The `substanceName` and `reactionDescription` ARE returned to an authorized
 * caller (the E.12 / `PatientSummary::$dateOfBirth` / `ProblemSummary`
 * precedent). NO cross-domain call.
 */
final class GetAllergyHandler
{
    public function __construct(
        private readonly AllergyReader $reader,
    ) {}

    public function __invoke(GetAllergy $query): AllergySummary
    {
        return $this->reader->summary($query->allergyId);
    }
}
