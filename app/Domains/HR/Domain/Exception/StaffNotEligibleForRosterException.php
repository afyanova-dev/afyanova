<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Domains\HR\Domain\ValueObject\StaffStatus;
use App\Shared\Domain\DomainException;

/**
 * Thrown when attempting to schedule a roster entry for a staff member who is not ACTIVE.
 */
final class StaffNotEligibleForRosterException extends DomainException
{
    public static function forStaff(string $staffId, StaffStatus $status): self
    {
        return new self(sprintf(
            'Cannot schedule roster entry for staff member [%s] because staff status is [%s]. Staff must be in ACTIVE status to be rostered.',
            $staffId,
            $status->value
        ));
    }

    public function errorCode(): string
    {
        return 'staff_not_eligible_for_roster';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
