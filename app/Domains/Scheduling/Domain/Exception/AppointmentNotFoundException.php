<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use DomainException;

final class AppointmentNotFoundException extends DomainException
{
    public static function forId(string $id): self
    {
        return new self("Appointment [{$id}] not found.");
    }
}
