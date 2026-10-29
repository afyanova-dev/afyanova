<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Contracts\Dto\AllergySummary;

/**
 * Read-side port for Allergy (IC §6.2 / §6.3; ADR-141 D-N). Implementation:
 * `Infrastructure/Reader/EloquentAllergyReader`. Returns DTOs only — never an
 * Eloquent model (IC §9).
 *
 * Exactly two read capabilities (ADR-141 D-N / D-O):
 *
 *   - `summary`     backs the `GetAllergy` protected query (by id);
 *   - `forPatient`  backs the `GetPatientAllergies` protected query (one
 *                   patient's allergy safety list; optional `clinical_status`
 *                   and/or `verification_status` filter; deterministic
 *                   ordering; NO pagination).
 *
 * >>> DELIBERATE DIVERGENCE FROM `ProblemReader` (ADR-141 D-M / D-N — the Q7
 *     tenant-wide allergy READ exception): there is NO `$inScopeFacilityIds`
 *     parameter and NO originating-facility filter. An allergy recorded at ANY
 *     facility in the caller's tenant is readable — a critical allergy is a
 *     tenant-wide patient-safety fact, not a facility-local observation. RLS
 *     still confines every row to the caller's tenant. This is an
 *     Allergy-read-path-only refinement; `ClinicalNote`, `VitalSignsObservation`,
 *     `Diagnosis` and `Problem` reads keep their originating-facility
 *     refinement. Do NOT "restore" a facility filter here. <<<
 *
 * C.5 publishes NO `activeForPatient()` method (`forPatient($id, 'ACTIVE', null)`
 * is the canonical active-allergy read — ADR-141 D-N), NO `ClinicalApi` method,
 * NO `clin_rm_*` projection and NO history / timeline reader (all deferred).
 */
interface AllergyReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the allergy does not exist in the
     * current tenant scope (RLS). NO facility-scope check — the coarse
     * `#[ProtectedQuery]` gate on `GetAllergy` (`AllergyPolicy::view`) IS the
     * authorization (ADR-141 D-M — reads are tenant-wide).
     */
    public function summary(string $allergyId): AllergySummary;

    /**
     * One patient's allergy safety list within the caller's tenant (ADR-141
     * D-M / D-N — TENANT-WIDE, no facility filter). An optional `$clinicalStatus`
     * (`AllergyStatus` value) and/or `$verificationStatus`
     * (`AllergyVerificationStatus` value) narrows the list.
     * `forPatient($patientId, 'ACTIVE', null)` is the canonical "active
     * allergies" read. Deterministic ordering; NO pagination.
     *
     * @return list<AllergySummary>
     */
    public function forPatient(string $patientId, ?string $clinicalStatus, ?string $verificationStatus): array;
}
