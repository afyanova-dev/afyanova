<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Lifecycle status of an Employment contract (25-hr-and-workforce-domain.md §8; ADR-206).
 *
 * DRAFT → ACTIVE ⇄ SUSPENDED → ENDED.
 */
enum EmploymentStatus: string
{
    case DRAFT = 'DRAFT';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case ENDED = 'ENDED';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isTerminal(): bool
    {
        return $this === self::ENDED;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT => in_array($target, [self::ACTIVE, self::ENDED], true),
            self::ACTIVE => in_array($target, [self::SUSPENDED, self::ENDED], true),
            self::SUSPENDED => in_array($target, [self::ACTIVE, self::ENDED], true),
            self::ENDED => false,
        };
    }
}
