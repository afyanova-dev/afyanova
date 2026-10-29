<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use DomainException;

final class AppointmentConflictException extends DomainException
{
    public static function forOverlappingInterval(string $resourceId, string $start, string $end): self
    {
        return new self("Resource [{$resourceId}] already has an appointment overlapping [{$start} – {$end}].");
    }
}
