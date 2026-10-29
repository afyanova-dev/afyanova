<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\ValueObject;

/**
 * Status of a waitlist entry (22-scheduling-and-appointments-domain.md §23–§27).
 */
enum WaitlistStatus: string
{
    case ACTIVE = 'ACTIVE';
    case OFFERED = 'OFFERED';
    case BOOKED = 'BOOKED';
    case EXPIRED = 'EXPIRED';
    case CANCELLED = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::BOOKED, self::EXPIRED, self::CANCELLED], true);
    }

    public function canOffer(): bool
    {
        return $this === self::ACTIVE;
    }

    public function canAcceptOrDecline(): bool
    {
        return $this === self::OFFERED;
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::ACTIVE, self::OFFERED], true);
    }
}
