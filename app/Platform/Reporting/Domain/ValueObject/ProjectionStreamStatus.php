<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Domain\ValueObject;

enum ProjectionStreamStatus: string
{
    case IDLE = 'IDLE';
    case BUILDING = 'BUILDING';
    case LIVE = 'LIVE';
    case REBUILDING = 'REBUILDING';
}
