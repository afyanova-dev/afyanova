<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\ValueObject;

/**
 * Operational status of a bookable slot (22-scheduling-and-appointments-domain.md §43).
 */
enum SlotStatus: string
{
    case OPEN = 'OPEN';
    case HELD = 'HELD';
    case BOOKED = 'BOOKED';
    case BLOCKED = 'BLOCKED';

    public function isAvailable(): bool
    {
        return $this === self::OPEN;
    }
}
