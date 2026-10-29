<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum InboundMessageStatus: string
{
    case RECEIVED = 'RECEIVED';
    case AUTHENTICATED = 'AUTHENTICATED';
    case TRANSLATED = 'TRANSLATED';
    case DISPATCHED = 'DISPATCHED';
    case ACK = 'ACK';
    case REJECTED = 'REJECTED';
    case DEAD_LETTER = 'DEAD_LETTER';
}
