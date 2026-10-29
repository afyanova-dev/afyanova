<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class EmploymentNotFoundException extends DomainException
{
    public static function forId(string $employmentId): self
    {
        return new self(sprintf('Employment contract [%s] was not found.', $employmentId));
    }

    public function errorCode(): string
    {
        return 'employment_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
