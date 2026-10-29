<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum PayloadSensitivity: string
{
    case STANDARD = 'STANDARD';
    case REDACTED = 'REDACTED';
    case MINIMIZED = 'MINIMIZED';
    case ENCRYPTED = 'ENCRYPTED';
}
