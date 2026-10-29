<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain;

use App\Domains\BedWard\Contracts\Dto\FacilityOccupancySummary;
use App\Domains\BedWard\Contracts\Dto\WardSummary;

/**
 * Read-side port for Ward queries (IC §6, §9).
 */
interface WardReader
{
    public function summary(string $wardId): WardSummary;

    /**
     * Live counts by status for a given ward.
     *
     * @return array<string, int>
     */
    public function census(string $wardId): array;

    /**
     * Authoritative live facility occupancy summary.
     */
    public function facilityOccupancy(string $facilityId): FacilityOccupancySummary;
}
