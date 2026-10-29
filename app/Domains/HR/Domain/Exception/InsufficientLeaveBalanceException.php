<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class InsufficientLeaveBalanceException extends DomainException
{
    public static function forStaffAndType(string $staffId, string $leaveTypeId, float $requestedDays, float $currentBalance): self
    {
        return new self(sprintf(
            'Staff [%s] has insufficient leave balance for leave type [%s]: requested %.2f days, but current balance is %.2f days.',
            $staffId,
            $leaveTypeId,
            $requestedDays,
            $currentBalance
        ));
    }

    public function errorCode(): string
    {
        return 'insufficient_leave_balance';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
