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
     * @return list<ClaimSummary>
     */
    public function getPatientClaims(string $patientId, ?string $status = null): array;

    /**
     * @return list<ClaimSummary>
     */
    public function getPayerClaims(string $payerId, ?string $status = null): array;
}
