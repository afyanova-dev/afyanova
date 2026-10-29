<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class DuplicateRosterSlotException extends DomainException
{
    public static function forStaffDateShift(string $staffId, string $date, string $shiftPatternId): self
    {
        return new self(sprintf(
            'Staff member [%s] already has an active roster entry for date [%s] and shift pattern [%s].',
            $staffId,
            $date,
            $shiftPatternId
        ));
    }

    public static function forSlot(string $staffId, string $date, string $shiftPatternId): self
    {
        return self::forStaffDateShift($staffId, $date, $shiftPatternId);
    }

    public function errorCode(): string
    {
        return 'duplicate_roster_slot';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
