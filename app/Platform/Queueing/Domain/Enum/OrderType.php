<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Enum;

enum OrderType: string
{
    case LAB_ORDER = 'LAB_ORDER';
    case PRESCRIPTION = 'PRESCRIPTION';
    case RADIOLOGY_REQUEST = 'RADIOLOGY_REQUEST';
    case PROCEDURE_BOOKING = 'PROCEDURE_BOOKING';
}
