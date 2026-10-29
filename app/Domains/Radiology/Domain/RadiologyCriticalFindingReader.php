<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain;

use App\Domains\Radiology\Contracts\Dto\RadiologyCriticalFindingSummary;

/**
 * Read-side port for RadiologyCriticalFinding queries (ADR-169 D-F). Returns
 * DTOs only.
 */
interface RadiologyCriticalFindingReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if absent from the current tenant
     * scope (RLS).
     */
    public function summary(string $criticalFindingId): RadiologyCriticalFindingSummary;

    /**
     * The unacknowledged critical findings at a facility (§101 / §161).
     *
     * @return list<RadiologyCriticalFindingSummary>
     */
    public function pendingForFacility(string $facilityId): array;
}
