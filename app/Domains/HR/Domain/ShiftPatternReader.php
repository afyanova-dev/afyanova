<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\ShiftPatternSummary;

/**
 * Read-side port for ShiftPattern queries (25-hr-and-workforce-domain.md §6, §10; ADR-206).
 */
interface ShiftPatternReader
{
    public function summary(string $shiftPatternId): ShiftPatternSummary;

    /**
     * @return list<ShiftPatternSummary>
     */
    public function listByFacility(string $facilityId, bool $activeOnly = true): array;
}
