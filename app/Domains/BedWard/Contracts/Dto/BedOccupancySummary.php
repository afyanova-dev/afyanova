<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Contracts\Dto;

/**
 * Minimal summary read of one bed occupancy.
 */
final readonly class BedOccupancySummary
{
    public function __construct(
        public string $occupancyId,
        public string $facilityId,
        public string $bedId,
        public string $encounterId,
        public string $patientId,
        public string $assignedByUserId,
        public string $status,
        public ?string $endReason,
        public string $occurredAt,
        public ?string $endedAt,
        public string $recordedAt,
    ) {}
}
