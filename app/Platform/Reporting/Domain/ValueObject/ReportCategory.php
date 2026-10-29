<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Domain\ValueObject;

enum ReportCategory: string
{
    case CLINICAL = 'CLINICAL';
    case FINANCIAL = 'FINANCIAL';
    case OPERATIONAL = 'OPERATIONAL';
    case STATUTORY = 'STATUTORY';
    case CUSTOM = 'CUSTOM';
}
