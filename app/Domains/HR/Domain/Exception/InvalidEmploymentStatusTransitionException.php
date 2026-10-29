<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Domains\HR\Domain\ValueObject\EmploymentStatus;
use App\Shared\Domain\DomainException;

/**
 * Thrown when an illegal lifecycle transition is attempted on an Employment contract.
 */
final class InvalidEmploymentStatusTransitionException extends DomainException
{
    public static function transition(EmploymentStatus $from, EmploymentStatus $to): self
    {
        return new self(sprintf(
            'Cannot transition Employment status from [%s] to [%s].',
            $from->value,
            $to->value
        ));
    }

    public function errorCode(): string
    {
        return 'invalid_employment_status_transition';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
