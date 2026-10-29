<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Domains\HR\Domain\ValueObject\AttendanceSessionStatus;
use App\Shared\Domain\DomainException;

final class InvalidAttendanceSessionTransitionException extends DomainException
{
    public static function forTransition(AttendanceSessionStatus $current, AttendanceSessionStatus $target): self
    {
        return new self(sprintf(
            'Cannot transition attendance session from status [%s] to status [%s].',
            $current->value,
            $target->value
        ));
    }

    public function errorCode(): string
    {
        return 'invalid_attendance_session_transition';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
