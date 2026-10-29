<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Domains\HR\Domain\ValueObject\LeaveRequestStatus;
use App\Shared\Domain\DomainException;

final class InvalidLeaveRequestStatusTransitionException extends DomainException
{
    public static function forTransition(LeaveRequestStatus $current, LeaveRequestStatus $target): self
    {
        return new self(sprintf(
            'Cannot transition leave request from status [%s] to status [%s].',
            $current->value,
            $target->value
        ));
    }

    public function errorCode(): string
    {
        return 'invalid_leave_request_status_transition';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
