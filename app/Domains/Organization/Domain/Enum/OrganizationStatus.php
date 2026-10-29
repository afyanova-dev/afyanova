<?php

declare(strict_types=1);

namespace App\Domains\Organization\Domain\Enum;

/** docs/00-foundation/02-organization.md §25. */
enum OrganizationStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
}
