<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Thrown when attempting to terminate a staff member who has upcoming published roster entries.
 * (ADR-206 Single-Aggregate Boundary Extension).
 */
final class StaffHasFutureRosterEntriesException extends DomainException
{
    public static function forStaff(string $staffId, int $futureCount): self
    {
        return new self(sprintf(
            'Staff member [%s] cannot be terminated while [%d] future published roster entry/entries exist. Reassign or cancel all future shifts prior to staff termination.',
            $staffId,
            $futureCount
        ));
    }

    public function errorCode(): string
    {
        return 'staff_has_future_roster_entries';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
