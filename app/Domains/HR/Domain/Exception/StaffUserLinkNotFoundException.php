<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class StaffUserLinkNotFoundException extends DomainException
{
    public static function forStaff(string $staffId): self
    {
        return new self(sprintf('No user link found for staff member [%s].', $staffId));
    }

    public function errorCode(): string
    {
        return 'staff_user_link_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
