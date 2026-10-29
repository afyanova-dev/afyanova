<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class RequesterCannotApproveOwnLeaveException extends DomainException
{
    public static function forUser(string $userId, string $staffId): self
    {
        return new self(sprintf(
            'User [%s] linked to staff [%s] cannot approve their own leave request (separation of duties).',
            $userId,
            $staffId
        ));
    }

    public function errorCode(): string
    {
        return 'requester_cannot_approve_own_leave';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
