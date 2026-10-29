<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class UserAlreadyLinkedException extends DomainException
{
    public static function forUser(string $userId, string $existingStaffId): self
    {
        return new self(sprintf(
            'User [%s] is already linked to staff member [%s]. A user can be linked to at most one staff member.',
            $userId,
            $existingStaffId
        ));
    }

    public function errorCode(): string
    {
        return 'user_already_linked';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
