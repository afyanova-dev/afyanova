<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Clocking direction for an attendance event (25-hr-and-workforce-domain.md §6).
 */
enum AttendanceDirection: string
{
    case IN = 'IN';
    case OUT = 'OUT';
    case BREAK_OUT = 'BREAK_OUT';
    case BREAK_IN = 'BREAK_IN';

    public function isClock(): bool
    {
        return in_array($this, [self::IN, self::OUT], true);
    }

    public function isBreak(): bool
    {
        return in_array($this, [self::BREAK_OUT, self::BREAK_IN], true);
    }
}
