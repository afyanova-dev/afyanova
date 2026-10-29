<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Origin source of an attendance event (25-hr-and-workforce-domain.md §6; ADR-206).
 */
enum AttendanceSource: string
{
    case DEVICE = 'DEVICE';
    case MOBILE = 'MOBILE';
    case MANUAL = 'MANUAL';
    case SYSTEM = 'SYSTEM';

    public function isAutomated(): bool
    {
        return in_array($this, [self::DEVICE, self::SYSTEM], true);
    }
}
