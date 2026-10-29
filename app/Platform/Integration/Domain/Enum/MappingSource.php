<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum MappingSource: string
{
    case INBOUND_OBSERVED = 'INBOUND_OBSERVED';
    case MANUAL = 'MANUAL';
    case DOMAIN_PROVIDED = 'DOMAIN_PROVIDED';
    case VERIFIED = 'VERIFIED';
}
