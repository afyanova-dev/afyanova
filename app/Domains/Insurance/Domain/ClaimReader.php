<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Contracts\Dto\ClaimSummary;

/**
 * Read-side port for Claim queries (IC §9; ADR-204).
 */
interface ClaimReader
{
    public function getById(string $id): ?ClaimSummary;

    public function getByClaimNumber(string $claimNumber): ?ClaimSummary;

    /**
     * @param  list<string>  $facilityIds
     * @return list<ClaimSummary>
     */
    public function getPatientClaims(string $patientId, array $facilityIds, ?string $status = null): array;

    /**
     * @param  list<string>  $facilityIds
     * @return list<ClaimSummary>
     */
    public function getPayerClaims(string $payerId, array $facilityIds, ?string $status = null): array;
}
