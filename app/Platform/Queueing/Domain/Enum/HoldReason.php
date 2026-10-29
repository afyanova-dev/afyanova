<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Enum;

enum HoldReason: string
{
    case FINANCIAL_HOLD = 'FINANCIAL_HOLD';
    case CLINICAL_HOLD = 'CLINICAL_HOLD';
    case ADMINISTRATIVE_HOLD = 'ADMINISTRATIVE_HOLD';
}
