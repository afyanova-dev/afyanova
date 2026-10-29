<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Lifecycle status of a RosterEntry (25-hr-and-workforce-domain.md §8; ADR-206).
 *
 * DRAFT -> PUBLISHED -> COMPLETED | ABSENT | SWAPPED | CANCELLED.
 */
enum RosterEntryStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case COMPLETED = 'COMPLETED';
    case ABSENT = 'ABSENT';
    case SWAPPED = 'SWAPPED';
    case CANCELLED = 'CANCELLED';

    public function isPublished(): bool
    {
        return $this === self::PUBLISHED;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::ABSENT, self::SWAPPED, self::CANCELLED], true);
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT => in_array($target, [self::PUBLISHED, self::CANCELLED], true),
            self::PUBLISHED => in_array($target, [self::COMPLETED, self::ABSENT, self::SWAPPED, self::CANCELLED], true),
            self::COMPLETED, self::ABSENT, self::SWAPPED, self::CANCELLED => false,
        };
    }
}
