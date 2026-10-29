<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Enum;

enum DocumentStatus: string
{
    case PENDING_SCAN = 'PENDING_SCAN';
    case SCANNING = 'SCANNING';
    case AVAILABLE = 'AVAILABLE';
    case QUARANTINED = 'QUARANTINED';
    case ON_HOLD = 'ON_HOLD';
    case RETAINED = 'RETAINED';
    case PENDING_DISPOSAL = 'PENDING_DISPOSAL';
    case DISPOSAL_FAILED = 'DISPOSAL_FAILED';
    case DISPOSED = 'DISPOSED';

    public function isAvailable(): bool
    {
        return $this === self::AVAILABLE;
    }

    public function canBeAccessed(): bool
    {
        return $this === self::AVAILABLE || $this === self::RETAINED;
    }

    public function isTerminal(): bool
    {
        return $this === self::DISPOSED;
    }

    public function isDisposalState(): bool
    {
        return $this === self::PENDING_DISPOSAL
            || $this === self::DISPOSAL_FAILED
            || $this === self::DISPOSED;
    }
}
