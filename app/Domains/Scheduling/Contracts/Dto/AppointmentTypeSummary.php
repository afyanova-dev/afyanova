<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Contracts\Dto;

final readonly class AppointmentTypeSummary
{
    public function __construct(
        public string $id,
        public ?string $facilityId,
        public string $code,
        public string $name,
        public int $durationMinutes,
        public int $bufferBeforeMinutes,
        public int $bufferAfterMinutes,
        public bool $isVirtual,
        public bool $allowPatientBooking,
        public bool $isActive,
    ) {}
}
