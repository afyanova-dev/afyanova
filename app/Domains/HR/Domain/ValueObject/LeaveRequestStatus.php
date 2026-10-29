<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Operational lifecycle status of a leave request (25-hr-and-workforce-domain.md §6, §8).
 */
enum LeaveRequestStatus: string
{
    case REQUESTED = 'REQUESTED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case TAKEN = 'TAKEN';

    public function isRequested(): bool
    {
        return $this === self::REQUESTED;
    }

    public function isApproved(): bool
    {
        return $this === self::APPROVED;
    }

    public function isTaken(): bool
    {
        return $this === self::TAKEN;
    }

    public function isCancelled(): bool
    {
        return $this === self::CANCELLED;
    }

    public function isRejected(): bool
    {
        return $this === self::REJECTED;
    }

    public function isConcluded(): bool
    {
        return in_array($this, [self::REJECTED, self::CANCELLED, self::TAKEN], true);
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::REQUESTED => in_array($target, [self::APPROVED, self::REJECTED, self::CANCELLED], true),
            self::APPROVED => in_array($target, [self::TAKEN, self::CANCELLED], true),
            self::REJECTED, self::CANCELLED, self::TAKEN => false,
        };
    }
}
