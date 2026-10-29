<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Attendance exception classifications (25-hr-and-workforce-domain.md §6).
 */
enum AttendanceExceptionType: string
{
    case NONE = 'NONE';
    case MISSING_IN = 'MISSING_IN';
    case MISSING_OUT = 'MISSING_OUT';
    case LATE = 'LATE';
    case EARLY_LEAVE = 'EARLY_LEAVE';
    case OVERTIME = 'OVERTIME';
    case DISPUTED = 'DISPUTED';

    public function isException(): bool
    {
        return $this !== self::NONE;
    }
}
