<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum VerificationStatus: string
{
    case UNVERIFIED = 'UNVERIFIED';
    case VERIFIED = 'VERIFIED';
    case DISPUTED = 'DISPUTED';
}
