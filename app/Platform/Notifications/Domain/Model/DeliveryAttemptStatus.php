<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

enum DeliveryAttemptStatus: string
{
    case QUEUED = 'QUEUED';
    case SENT = 'SENT';
    case DELIVERED = 'DELIVERED';
    case FAILED = 'FAILED';
    case SUPPRESSED = 'SUPPRESSED';
}
