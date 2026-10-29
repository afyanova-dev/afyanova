<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Domains\HR\Domain\ValueObject\StaffStatus;
use App\Shared\Domain\DomainException;

/**
 * Thrown when attempting to start or reinstate employment for a staff member who is TERMINATED or SUSPENDED.
 */
final class StaffNotEligibleForEmploymentException extends DomainException
{
    public static function forStaff(string $staffId, StaffStatus $status): self
    {
        return new self(sprintf(
            'Cannot activate employment for staff member [%s] because staff status is [%s]. Staff must be in ACTIVE or ONBOARDING status.',
            $staffId,
            $status->value
        ));
    }

    public function errorCode(): string
    {
        return 'staff_not_eligible_for_employment';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
