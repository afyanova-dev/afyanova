<?php

declare(strict_types=1);

namespace App\Domains\Identity\Domain\Enum;

/** docs/00-foundation/03-identity-rbac.md §37. */
enum AssignmentStatus: string
{
    case Pending = 'PENDING';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';
}
