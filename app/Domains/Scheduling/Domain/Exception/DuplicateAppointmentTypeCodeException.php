<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use RuntimeException;

final class DuplicateAppointmentTypeCodeException extends RuntimeException
{
    public static function forCode(string $code): self
    {
        return new self("Appointment type with code [{$code}] already exists in this tenant.");
    }
}
