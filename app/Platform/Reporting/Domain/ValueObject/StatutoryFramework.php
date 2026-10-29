<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Domain\ValueObject;

enum StatutoryFramework: string
{
    case HMIS_MTUHA = 'HMIS_MTUHA';
    case DHIS2 = 'DHIS2';
    case SURVEILLANCE = 'SURVEILLANCE';
}
