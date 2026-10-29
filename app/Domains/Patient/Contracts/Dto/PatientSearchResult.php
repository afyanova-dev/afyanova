<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Dto;

/**
 * One row of a `SearchPatients` result (ADR-129 A). A DEDICATED, PHI-minimal
 * read model — deliberately NOT {@see PatientSummary} (ADR-125's cross-domain
 * display DTO stays decoupled from the search contract, and it carries
 * `dateOfBirth`).
 *
 * Carries NO date of birth, phone, email, address or identifier value: a search
 * result row exists only to let a human pick the right identity and then open
 * the record (10-patient-domain.md §51, §52). `displayName` is the single
 * free-text field, and it is the minimum needed to disambiguate.
 *
 * `status` discloses `MERGED` / `INACTIVE` / `DECEASED` as a coded value.
 * `survivingPatientId` is populated ONLY when `status = MERGED`, resolved from
 * the ACTIVE `pat_patient_identity_history` row so an old / merged patient
 * number still resolves to the canonical record instead of returning nothing
 * (Invariant 27; ADR-128, ADR-129 H).
 */
final readonly class PatientSearchResult
{
    public function __construct(
        public string $patientId,
        public string $patientNumber,
        public string $displayName,
        public string $sex,
        public string $status,
        public bool $isProvisional,
        public bool $hasAccessibleFacilityRegistration,
        public ?string $survivingPatientId,
    ) {}
}
