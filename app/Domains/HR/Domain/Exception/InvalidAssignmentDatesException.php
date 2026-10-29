<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class InvalidAssignmentDatesException extends DomainException
{
    public static function endBeforeStart(string $from, string $to): self
    {
        return new self(sprintf('Assignment effective_to [%s] cannot be earlier than effective_from [%s].', $to, $from));
    }

    public function errorCode(): string
    {
        return 'invalid_assignment_dates';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
