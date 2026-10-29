<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum OutboundMessageStatus: string
{
    case QUEUED = 'QUEUED';
    case SENDING = 'SENDING';
    case SENT = 'SENT';
    case ACK = 'ACK';
    case FAILED = 'FAILED';
    case DEAD_LETTER = 'DEAD_LETTER';
}
