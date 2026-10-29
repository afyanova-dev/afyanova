<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Enum;

enum TokenAction: string
{
    case METADATA = 'METADATA';
    case DOWNLOAD = 'DOWNLOAD';
}
