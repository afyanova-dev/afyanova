<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class StaffAlreadyLinkedException extends DomainException
{
    public static function forStaff(string $staffId, string $existingUserId): self
    {
        return new self(sprintf(
            'Staff member [%s] is already linked to user [%s]. A staff member can be linked to at most one user.',
            $staffId,
            $existingUserId
        ));
    }

    public function errorCode(): string
    {
        return 'staff_already_linked';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
