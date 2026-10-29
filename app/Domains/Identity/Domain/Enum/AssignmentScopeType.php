<?php

declare(strict_types=1);

namespace App\Domains\Identity\Domain\Enum;

/** docs/00-foundation/02-organization.md §19; docs/03 §12. */
enum AssignmentScopeType: string
{
    case Organization = 'ORG';
    case Facility = 'FACILITY';
    case Department = 'DEPARTMENT';
    case Unit = 'UNIT';
}
