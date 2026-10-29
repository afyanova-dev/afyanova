<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class OverlappingLeaveRequestException extends DomainException
{
    public static function forPeriod(string $staffId, string $startDate, string $endDate): self
    {
        return new self(sprintf(
            'Staff [%s] already has an approved or pending leave overlapping with period [%s to %s].',
            $staffId,
            $startDate,
            $endDate
        ));
    }

    public function errorCode(): string
    {
        return 'overlapping_leave_request';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
