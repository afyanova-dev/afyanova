<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain;

use App\Domains\Laboratory\Contracts\Dto\LabResultAmendmentSummary;
use App\Domains\Laboratory\Contracts\Dto\LabResultSummary;

/**
 * Read-side port for LabResult queries (ADR-159 D-G; ADR-160 D-G).
 */
interface LabResultReader
{
    public function summary(string $resultId): LabResultSummary;

    /**
     * All results for an order (§105 — the underlying result data behind a
     * laboratory report), parameter order.
     *
     * @return list<LabResultSummary>
     */
    public function forOrder(string $orderId): array;

    /**
     * The correction / cancellation history of one result, sequence order
     * (§137; ADR-160 D-G).
     *
     * @return list<LabResultAmendmentSummary>
     */
    public function amendments(string $resultId): array;

    /**
     * Result ids at a facility for a given test that are VERIFIED, not yet
     * released, and not already on hold — the results a failed QC run holds
     * (§85; ADR-163 D-E). `$testDefinitionId` null → all tests at the facility.
     *
     * @return list<string>
     */
    public function verifiedUnreleasedIdsForTest(string $facilityId, ?string $testDefinitionId): array;

    /**
     * Result ids at a facility currently on hold with `$reason` (for a given
     * test) — the results a reviewed QC run releases (§159; ADR-163 D-E).
     *
     * @return list<string>
     */
    public function heldIdsForTest(string $facilityId, ?string $testDefinitionId, string $reason): array;
}
