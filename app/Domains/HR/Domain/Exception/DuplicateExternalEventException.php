<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class DuplicateExternalEventException extends DomainException
{
    public static function forExternalEventId(string $externalEventId): self
    {
        return new self(sprintf('Attendance event with external event ID [%s] already ingested.', $externalEventId));
    }

    public function errorCode(): string
    {
        return 'duplicate_external_attendance_event';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
