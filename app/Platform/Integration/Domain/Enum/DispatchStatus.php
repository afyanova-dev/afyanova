<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum DispatchStatus: string
{
    case PENDING = 'PENDING';
    case DISPATCHED = 'DISPATCHED';
    case COMMITTED = 'COMMITTED';
    case REJECTED = 'REJECTED';
    case FAILED = 'FAILED';
}
