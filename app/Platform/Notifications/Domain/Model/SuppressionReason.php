<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

enum SuppressionReason: string
{
    case OPT_OUT = 'OPT_OUT';
    case QUIET_HOURS = 'QUIET_HOURS';
    case INVALID_ADDRESS = 'INVALID_ADDRESS';
    case NO_CONSENT = 'NO_CONSENT';
    case NO_USER_ACCOUNT = 'NO_USER_ACCOUNT';
    case RATE_LIMITED = 'RATE_LIMITED';
    case DUPLICATE = 'DUPLICATE';
}
