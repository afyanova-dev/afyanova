<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Dto;

/**
 * Live facility bed occupancy summary (24-bed-and-ward-management-domain.md §10; ADR-148 D-I).
 */
final readonly class FacilityOccupancySummary
{
    /**
     * @param  array<string, array{total: int, occupied: int, available: int, blocked: int, cleaning: int, occupancy_rate: float}>  $byWard
     * @param  array<string, array{total: int, occupied: int, available: int, blocked: int, cleaning: int, occupancy_rate: float}>  $byBedClass
     */
    public function __construct(
        public string $facilityId,
        public int $totalBeds,
        public int $occupiedBeds,
        public int $availableBeds,
        public int $cleaningBeds,
        public int $blockedBeds,
        public float $occupancyRate,
        public array $byWard,
        public array $byBedClass,
    ) {}
}
