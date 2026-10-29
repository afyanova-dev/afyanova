<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class LeaveTypeNotFoundException extends DomainException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Leave type [%s] not found.', $id));
    }

    public static function forCode(string $code): self
    {
        return new self(sprintf('Leave type with code [%s] not found.', $code));
    }

    public function errorCode(): string
    {
        return 'leave_type_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
