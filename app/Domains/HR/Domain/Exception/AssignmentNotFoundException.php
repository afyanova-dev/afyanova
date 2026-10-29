<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class AssignmentNotFoundException extends DomainException
{
    public static function forId(string $assignmentId): self
    {
        return new self(sprintf('Assignment [%s] not found.', $assignmentId));
    }

    public function errorCode(): string
    {
        return 'assignment_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
