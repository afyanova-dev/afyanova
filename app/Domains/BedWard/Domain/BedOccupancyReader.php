<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain;

use App\Domains\BedWard\Contracts\Dto\BedOccupancyRef;
use App\Domains\BedWard\Contracts\Dto\BedOccupancySummary;

/**
 * Read-side port for BedOccupancy queries and BedWardApi (IC §6, §9).
 */
interface BedOccupancyReader
{
    public function summary(string $occupancyId): BedOccupancySummary;

    public function activeForEncounter(string $encounterId): ?BedOccupancyRef;

    /**
     * @return array{current: BedOccupancySummary|null, history: list<BedOccupancySummary>}
     */
    public function currentAndHistoryForEncounter(string $encounterId): array;

    /**
     * @return list<BedOccupancySummary>
     */
    public function historyForBed(string $bedId): array;
}
