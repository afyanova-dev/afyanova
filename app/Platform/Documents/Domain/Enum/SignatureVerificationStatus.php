<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Enum;

enum SignatureVerificationStatus: string
{
    case VALID = 'VALID';
    case TAMPERED = 'TAMPERED';
    case VERSION_DISPOSED = 'VERSION_DISPOSED';
}
