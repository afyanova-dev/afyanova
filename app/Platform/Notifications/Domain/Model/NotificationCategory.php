<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

enum NotificationCategory: string
{
    case TRANSACTIONAL = 'TRANSACTIONAL';
    case REMINDER = 'REMINDER';
    case OUTREACH = 'OUTREACH';
    case SYSTEM = 'SYSTEM';
}
