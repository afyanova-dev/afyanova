<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Domains\HR\Domain\ValueObject\StaffStatus;
use App\Shared\Domain\DomainException;

/**
 * Thrown when an illegal lifecycle transition is attempted on a Staff record.
 */
final class InvalidStaffStatusTransitionException extends DomainException
{
    public static function transition(StaffStatus $from, StaffStatus $to): self
    {
        return new self(sprintf(
            'Cannot transition Staff status from [%s] to [%s].',
            $from->value,
            $to->value
        ));
    }

    public function errorCode(): string
    {
        return 'invalid_staff_status_transition';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
