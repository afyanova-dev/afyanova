<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Thrown when attempting to terminate a staff member who still has active or suspended employments.
 * (25-hr-and-workforce-domain.md Invariant 7.13; ADR-206).
 */
final class StaffHasActiveEmploymentException extends DomainException
{
    public static function forStaff(string $staffId, int $openCount): self
    {
        return new self(sprintf(
            'Staff member [%s] cannot be terminated while [%d] open employment contract(s) exist in ACTIVE or SUSPENDED status. All employments must first be concluded via EndEmployment.',
            $staffId,
            $openCount
        ));
    }

    public function errorCode(): string
    {
        return 'staff_has_active_employment';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
