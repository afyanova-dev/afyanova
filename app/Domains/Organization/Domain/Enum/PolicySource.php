<?php

declare(strict_types=1);

namespace App\Domains\Organization\Domain\Enum;

/** docs/00-foundation/02-organization.md §22. */
enum PolicySource: string
{
    case OrgDefault = 'ORG_DEFAULT';
    case FacilityOverride = 'FACILITY_OVERRIDE';
}
