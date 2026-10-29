<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Enum;

enum QueueOperatingStatus: string
{
    case ACTIVE = 'ACTIVE';
    case PAUSED = 'PAUSED';
    case CLOSED = 'CLOSED';

    public function isCallable(): bool
    {
        return $this === self::ACTIVE;
    }

    public function isPaused(): bool
    {
        return $this === self::PAUSED;
    }

    public function isClosed(): bool
    {
        return $this === self::CLOSED;
    }
}
