<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Derived operational status for real-time workforce availability (25-hr-and-workforce-domain.md §6, §7.10).
 *
 * Availability is strictly derived: Roster − Approved Leave − Actual Attendance.
 */
enum WorkforceAvailabilityStatus: string
{
    case ON_SHIFT = 'ON_SHIFT';
    case SCHEDULED_NOT_CLOCKED_IN = 'SCHEDULED_NOT_CLOCKED_IN';
    case ON_LEAVE = 'ON_LEAVE';
    case OFF_DUTY = 'OFF_DUTY';

    public function isAvailable(): bool
    {
        return $this === self::ON_SHIFT;
    }

    public function isWorking(): bool
    {
        return in_array($this, [self::ON_SHIFT, self::SCHEDULED_NOT_CLOCKED_IN], true);
    }
}
