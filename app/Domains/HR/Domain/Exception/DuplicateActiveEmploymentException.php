<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Thrown when attempting to start or reinstate an employment contract when another
 * ACTIVE contract already exists for the staff member (25-hr-and-workforce-domain.md Invariant 7.4; ADR-206).
 */
final class DuplicateActiveEmploymentException extends DomainException
{
    public static function forStaff(string $staffId, string $existingEmploymentId): self
    {
        return new self(sprintf(
            'Staff member [%s] already has an active employment contract [%s]. At most one active employment contract is permitted per staff member (Invariant 7.4, ADR-206).',
            $staffId,
            $existingEmploymentId
        ));
    }

    public function errorCode(): string
    {
        return 'duplicate_active_employment';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
