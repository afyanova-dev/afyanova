<?php

declare(strict_types=1);

namespace App\Domains\Organization\Domain\Enum;

/** docs/00-foundation/02-organization.md §24; §06 §99. */
enum FacilityStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Closed = 'CLOSED';
}
