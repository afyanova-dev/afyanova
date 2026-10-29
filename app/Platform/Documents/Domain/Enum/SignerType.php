<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Enum;

enum SignerType: string
{
    case STAFF = 'STAFF';
    case PATIENT = 'PATIENT';
    case EXTERNAL_PARTY = 'EXTERNAL_PARTY';
}
