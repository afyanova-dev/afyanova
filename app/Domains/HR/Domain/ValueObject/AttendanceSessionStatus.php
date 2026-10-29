<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Operational lifecycle status of an attendance session (25-hr-and-workforce-domain.md §6).
 */
enum AttendanceSessionStatus: string
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
    case RECONCILED = 'RECONCILED';

    public function isOpen(): bool
    {
        return $this === self::OPEN;
    }

    public function isClosed(): bool
    {
        return $this === self::CLOSED;
    }

    public function isReconciled(): bool
    {
        return $this === self::RECONCILED;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::OPEN => in_array($target, [self::CLOSED, self::RECONCILED], true),
            self::CLOSED => $target === self::RECONCILED,
            self::RECONCILED => false,
        };
    }
}
