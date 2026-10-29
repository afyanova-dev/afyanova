<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class InvalidAssignmentScopeException extends DomainException
{
    public static function missingDepartment(): self
    {
        return new self('Department ID is required when assignment scope is DEPARTMENT or UNIT.');
    }

    public static function missingUnit(): self
    {
        return new self('Unit ID is required when assignment scope is UNIT.');
    }

    public function errorCode(): string
    {
        return 'invalid_assignment_scope';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
