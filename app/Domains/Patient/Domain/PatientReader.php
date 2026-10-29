<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain;

use App\Domains\Patient\Contracts\Dto\PatientGlance;
use App\Domains\Patient\Contracts\Dto\PatientNotificationContactDto;
use App\Domains\Patient\Contracts\Dto\PatientProfileData;
use App\Domains\Patient\Contracts\Dto\PatientSearchDetails;
use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-side port for Patient (IMPLEMENTATION-CONTRACTS.md §6.3). Implementation:
 * Infrastructure/Reader/EloquentPatientReader. Returns DTOs / scalars only —
 * never an Eloquent model (IC §9).
 */
interface PatientReader
{
    /**
     * @param  list<string>  $patientIds
     * @return array<string, PatientSearchDetails> Keyed by patient id
     */
    public function searchDetails(array $patientIds, string $facilityId): array;

    public function exists(string $patientId): bool;

    public function isRegisteredAtFacility(string $patientId, string $facilityId): bool;

    /**
     * Throws App\Shared\Domain\NotFound if the patient does not exist in the
     * current tenant scope.
     */
    public function summary(string $patientId): PatientSummary;

    /**
     * Read multiple patient summaries by IDs within the current tenant scope (RLS).
     * Missing or cross-tenant IDs are omitted from the returned array.
     *
     * @param  list<string>  $patientIds
     * @return array<string, PatientSummary> Keyed by patientId
     */
    public function summaries(array $patientIds): array;

    public function notificationContact(string $patientId): ?PatientNotificationContactDto;

    /**
     * Rich identity dossier preview for the Patient Glance console (verified
     * identifiers, facility reach, surviving-patient link).
     *
     * Throws App\Shared\Domain\NotFound if the patient does not exist in the
     * current tenant scope. Returns only VERIFIED patient identifiers.
     */
    public function glance(string $patientId, string $facilityId): PatientGlance;

    /**
     * Complete 360° Identity Dossier for the Patient Profile workspace.
     */
    public function profile(string $patientId, string $facilityId): PatientProfileData;

    /**
     * @return LengthAwarePaginator<int, PatientSearchResult>
     */
    public function recent(string $facilityId, int $page = 1, int $perPage = 25): LengthAwarePaginator;
}
