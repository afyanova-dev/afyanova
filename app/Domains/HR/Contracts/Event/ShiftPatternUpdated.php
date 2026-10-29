<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when a shift pattern template is updated (25-hr-and-workforce-domain.md §6).
 */
final readonly class ShiftPatternUpdated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $shiftPatternId,
        public string $facilityId,
        public string $code,
        public string $name,
        public string $startTime,
        public string $endTime,
        public int $breakDurationMinutes,
        public bool $isOvernight,
        public ?string $colorCode,
        public bool $isActive,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
