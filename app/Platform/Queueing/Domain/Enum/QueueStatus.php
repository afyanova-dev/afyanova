<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Enum;

enum QueueStatus: string
{
    case WAITING = 'WAITING';
    case CALLED = 'CALLED';
    case IN_SERVICE = 'IN_SERVICE';
    case HOLD = 'HOLD';
    case COMPLETED = 'COMPLETED';
    case NO_RESPONSE = 'NO_RESPONSE';
    case SKIPPED = 'SKIPPED';
    case LEFT_WITHOUT_BEING_SEEN = 'LEFT_WITHOUT_BEING_SEEN';
    case EXPIRED = 'EXPIRED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::EXPIRED, self::LEFT_WITHOUT_BEING_SEEN => true,
            default => false,
        };
    }

    public function isCallable(): bool
    {
        return match ($this) {
            self::WAITING, self::NO_RESPONSE, self::SKIPPED => true,
            default => false,
        };
    }
}
