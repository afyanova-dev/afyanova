<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Enum;

enum DocumentKind: string
{
    case UPLOAD = 'UPLOAD';
    case GENERATED = 'GENERATED';
}
