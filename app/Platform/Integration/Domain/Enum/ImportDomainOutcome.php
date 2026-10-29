<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum ImportDomainOutcome: string
{
    case PENDING = 'PENDING';
    case COMMITTED = 'COMMITTED';
    case REJECTED = 'REJECTED';
}
