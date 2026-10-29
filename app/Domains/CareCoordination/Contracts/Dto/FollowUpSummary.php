<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Dto;

/**
 * Summary read of one follow-up (ADR-154 D-F). A protected read model, not an
 * event payload.
 */
final readonly class FollowUpSummary
{
    public function __construct(
        public string $followUpId,
        public string $facilityId,
        public string $patientId,
        public string $sourceEncounterId,
        public string $dueFrom,
        public string $dueTo,
        public ?string $reason,
        public ?string $instructions,
        public ?string $bookedAppointmentId,
        public ?string $resultingEncounterId,
        public string $status,
        public ?string $outcomeRecordedAt,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
