<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts;

use App\Domains\Patient\Contracts\Dto\PatientNotificationContactDto;
use App\Domains\Patient\Contracts\Dto\PatientSearchDetails;
use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\Dto\PharmacyOnlyPatientRegistrationOutcome;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The ONLY Patient surface other modules may import (IC §2.1; ADR-114,
 * ADR-125). Grows per consumer — do not add a method without a concrete
 * caller (IC §26).
 */
interface PatientApi
{
    /**
     * Protected bulk search enrichment for an authorized facility search.
     *
     * @param  list<string>  $patientIds
     * @return array<string, PatientSearchDetails> Keyed by patient id
     */
    public function getPatientSearchDetails(array $patientIds, string $facilityId): array;

    /** Unprotected existence check — no PHI exposed (mere existence). */
    public function patientExists(string $patientId): bool;

    /** Unprotected relationship check — no patient data exposed. */
    public function patientIsRegisteredAtFacility(string $patientId, string $facilityId): bool;

    /**
     * Protected read (IC §6.2) — throws App\Shared\Domain\NotFound if the
     * patient does not exist in the current tenant scope, and an
     * authorization exception if the caller lacks patient.patients.read.
     */
    public function getPatientSummary(string $patientId): PatientSummary;

    /**
     * Protected bulk read (IC §6.2; ADR-125) — returns an associative map of
     * [patientId => PatientSummary] for all requested IDs that exist in the
     * current tenant scope and are visible to the caller.
     *
     * Missing or cross-tenant IDs are omitted from the map.
     * Throws an authorization exception if the caller lacks patient.patients.read.
     *
     * @param  list<string>  $patientIds
     * @return array<string, PatientSummary> Keyed by patientId
     */
    public function getPatientSummaries(array $patientIds): array;

    /**
     * Protected read for notification delivery — returns verified contact points
     * (phone, email) and communication consent basis.
     */
    public function getNotificationContact(string $patientId): ?PatientNotificationContactDto;

    /**
     * Bounded search of patients registered at the active facility
     * (ADR-114, ADR-129). The facility is resolved from trusted request scope.
     *
     * @return LengthAwarePaginator<int, PatientSearchResult>
     */
    public function searchPatients(
        ?string $patientNumber = null,
        ?string $nationalId = null,
        ?string $insuranceId = null,
        ?string $phone = null,
        ?string $nameFragment = null,
        ?string $dateOfBirth = null,
        int $page = 1,
        int $perPage = 25,
    ): LengthAwarePaginator;

    /**
     * Record the patient's participation at a facility (ADR-114). Idempotent
     * is NOT assumed here: a repeat call for the same (patient, facility)
     * throws App\Domains\Patient\Domain\Exception\PatientAlreadyRegisteredAtFacility
     * (IC §9.1) — the caller is responsible for checking first if a
     * check-then-call is what it needs.
     */
    public function registerAtFacility(string $patientId, string $facilityId): void;

    /**
     * Create a provisional patient identity for an external pharmacy
     * prescription, registered only at the active facility. The command bus
     * enforces the dedicated patient.patients.create_pharmacy_only permission
     * and the usual duplicate-detection rules.
     */
    public function registerPharmacyOnlyPatient(
        string $patientId,
        string $actorId,
        string $givenName,
        string $familyName,
        string $sex,
        ?string $primaryPhone,
    ): PharmacyOnlyPatientRegistrationOutcome;
}
