<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class AttendanceSessionNotFoundException extends DomainException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Attendance session [%s] not found.', $id));
    }

    public function errorCode(): string
    {
        return 'attendance_session_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
