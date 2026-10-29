<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Lifecycle status of an organizational Assignment (25-hr-and-workforce-domain.md §8; ADR-206).
 *
 * ACTIVE -> ENDED.
 */
enum AssignmentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case ENDED = 'ENDED';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isEnded(): bool
    {
        return $this === self::ENDED;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::ACTIVE => $target === self::ENDED,
            self::ENDED => false,
        };
    }
}
