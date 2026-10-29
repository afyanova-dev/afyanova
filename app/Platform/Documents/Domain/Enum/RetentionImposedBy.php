<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Enum;

enum RetentionImposedBy: string
{
    case SOURCE_DOMAIN = 'SOURCE_DOMAIN';
    case GOVERNANCE = 'GOVERNANCE';
    case LEGAL_HOLD = 'LEGAL_HOLD';
}
