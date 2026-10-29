<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

enum RecipientType: string
{
    case PATIENT = 'PATIENT';
    case STAFF = 'STAFF';
    case USER = 'USER';
}
