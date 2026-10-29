<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Lifecycle status of a Staff member (25-hr-and-workforce-domain.md §8; ADR-206).
 *
 * PROSPECT → ONBOARDING → ACTIVE ⇄ SUSPENDED → TERMINATED.
 *
 * Note: Absence / on-leave is derived from approved leave requests,
 * never a stored staff status (Canonical Correction #4).
 */
enum StaffStatus: string
{
    case PROSPECT = 'PROSPECT';
    case ONBOARDING = 'ONBOARDING';
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case TERMINATED = 'TERMINATED';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isTerminal(): bool
    {
        return $this === self::TERMINATED;
    }

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PROSPECT => in_array($target, [self::ONBOARDING, self::ACTIVE, self::TERMINATED], true),
            self::ONBOARDING => in_array($target, [self::ACTIVE, self::TERMINATED], true),
            self::ACTIVE => in_array($target, [self::SUSPENDED, self::TERMINATED], true),
            self::SUSPENDED => in_array($target, [self::ACTIVE, self::TERMINATED], true),
            self::TERMINATED => false,
        };
    }
}
