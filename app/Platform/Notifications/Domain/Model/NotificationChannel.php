<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

enum NotificationChannel: string
{
    case SMS = 'SMS';
    case EMAIL = 'EMAIL';
    case IN_APP = 'IN_APP';
}
