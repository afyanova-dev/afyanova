<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Contracts\Dto\MemberCoverageSummary;

/**
 * Read-side port for member coverage (IC §6.2; ADR-199 D-C). Returns DTOs only.
 */
interface MemberCoverageReader
{
    /** Throws `App\Domains\Insurance\Domain\Exception\MemberCoverageNotFound` if absent (RLS). */
    public function summary(string $memberCoverageId): MemberCoverageSummary;

    /**
     * Every coverage for a patient — ACTIVE first, then by priority.
     *
     * @return list<MemberCoverageSummary>
     */
    public function forPatient(string $patientId): array;

    /**
     * @return list<MemberCoverageSummary> newest first
     */
    public function search(?string $planId, ?string $payerId, ?string $status): array;
}
