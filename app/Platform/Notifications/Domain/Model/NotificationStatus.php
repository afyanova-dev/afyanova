<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

enum NotificationStatus: string
{
    case ACCEPTED = 'ACCEPTED';
    case RENDERED = 'RENDERED';
    case DISPATCHED = 'DISPATCHED';
    case DELIVERED = 'DELIVERED';
    case FAILED = 'FAILED';
    case SUPPRESSED = 'SUPPRESSED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::DELIVERED, self::FAILED, self::SUPPRESSED], true);
    }
}
