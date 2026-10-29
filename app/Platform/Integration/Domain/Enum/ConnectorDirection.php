<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum ConnectorDirection: string
{
    case INBOUND = 'INBOUND';
    case OUTBOUND = 'OUTBOUND';
    case BIDIRECTIONAL = 'BIDIRECTIONAL';
}
