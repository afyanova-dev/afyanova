<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use DomainException;

final class InvalidScheduleException extends DomainException
{
    public static function invalidTimes(string $startTime, string $endTime): self
    {
        return new self("Schedule start time [{$startTime}] must precede end time [{$endTime}].");
    }

    public static function invalidDateRange(string $effectiveFrom, string $effectiveTo): self
    {
        return new self("Schedule effective from [{$effectiveFrom}] must be before effective to [{$effectiveTo}].");
    }
}
