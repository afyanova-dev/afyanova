<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\Enum;

enum ConfidentialityLevel: string
{
    case STANDARD = 'STANDARD';
    case RESTRICTED = 'RESTRICTED';

    public function isRestricted(): bool
    {
        return $this === self::RESTRICTED;
    }
}
