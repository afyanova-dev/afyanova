<?php

declare(strict_types=1);

namespace App\Domains\Identity\Domain\Enum;

/** docs/00-foundation/03-identity-rbac.md §35. */
enum RoleStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Retired = 'RETIRED';

    public function grantsAccess(): bool
    {
        return $this === self::Active;
    }
}
