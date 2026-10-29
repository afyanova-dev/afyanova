<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Dto;

final readonly class SlotSummary
{
    public function __construct(
        public string $id,
        public string $facilityId,
        public string $resourceId,
        public ?string $appointmentTypeId,
        public string $slotDate,
        public string $startTime,
        public string $endTime,
        public int $capacity,
        public int $bookedCount,
        public string $status,
        public ?string $heldUntil,
        public ?string $heldBy,
    ) {}
}
