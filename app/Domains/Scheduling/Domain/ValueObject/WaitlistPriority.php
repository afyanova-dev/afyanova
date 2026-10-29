<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\ValueObject;

/**
 * Priority tier for waitlist candidates (22-scheduling-and-appointments-domain.md §25, §115–§117).
 */
enum WaitlistPriority: string
{
    case ROUTINE = 'ROUTINE';
    case URGENT = 'URGENT';
    case EMERGENCY = 'EMERGENCY';

    /**
     * Lower rank indicates higher urgency (for sorting: EMERGENCY first).
     */
    public function rank(): int
    {
        return match ($this) {
            self::EMERGENCY => 1,
            self::URGENT => 2,
            self::ROUTINE => 3,
        };
    }
}
