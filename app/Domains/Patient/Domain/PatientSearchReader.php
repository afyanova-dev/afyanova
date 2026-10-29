<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain;

use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Domain\Search\PatientSearchCriteria;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-side port for `SearchPatients` (IMPLEMENTATION-CONTRACTS.md §6.3;
 * ADR-129). Implementation: Infrastructure/Reader/EloquentPatientSearchReader.
 * Returns a bounded paginator of DTOs only — never an Eloquent model or builder
 * (IC §9).
 *
 * Search results are restricted to registrations at the active facility.
 * Tenant isolation is additionally enforced by RLS and BelongsToTenant scope.
 */
interface PatientSearchReader
{
    /**
     * @return LengthAwarePaginator<int, PatientSearchResult>
     */
    public function search(
        PatientSearchCriteria $criteria,
        string $searchingUserId,
        string $facilityId,
        int $page,
        int $perPage,
    ): LengthAwarePaginator;
}
