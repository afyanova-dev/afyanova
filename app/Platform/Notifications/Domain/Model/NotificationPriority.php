<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

enum NotificationPriority: string
{
    case ROUTINE = 'ROUTINE';
    case URGENT = 'URGENT';
    case EMERGENCY = 'EMERGENCY';

    public function bypassesQuietHours(): bool
    {
        return $this === self::EMERGENCY;
    }
}
