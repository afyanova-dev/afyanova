<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Dto;

/**
 * Minimal active bed occupancy reference returned by BedWardApi::activeOccupancyFor
 * (ADR-147 D-H, ADR-148 D-K).
 */
final readonly class BedOccupancyRef
{
    public function __construct(
        public string $occupancyId,
        public string $bedId,
        public string $wardId,
        public string $facilityId,
        public string $encounterId,
        public string $patientId,
        public string $startedAt,
    ) {}
}
