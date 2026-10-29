<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class DuplicatePrimaryAssignmentException extends DomainException
{
    public static function forStaff(string $staffId, string $existingAssignmentId): self
    {
        return new self(sprintf(
            'Staff member [%s] already has an active primary assignment [%s]. At most one active primary assignment is permitted per staff member.',
            $staffId,
            $existingAssignmentId
        ));
    }

    public function errorCode(): string
    {
        return 'duplicate_primary_assignment';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
