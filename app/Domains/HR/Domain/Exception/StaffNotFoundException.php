<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class StaffNotFoundException extends DomainException
{
    public static function forId(string $staffId): self
    {
        return new self(sprintf('Staff member [%s] was not found.', $staffId));
    }

    public static function forNumber(string $staffNumber): self
    {
        return new self(sprintf('Staff member with number [%s] was not found.', $staffNumber));
    }

    public function errorCode(): string
    {
        return 'staff_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
