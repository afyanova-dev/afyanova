<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Thrown when attempting to swap shifts between roster entries that do not meet swap prerequisites.
 */
final class ShiftSwapMismatchException extends DomainException
{
    public static function notBothPublished(string $entryAId, string $entryBId): self
    {
        return new self(sprintf(
            'Cannot swap shifts between roster entry [%s] and [%s]: both roster entries must be in PUBLISHED status.',
            $entryAId,
            $entryBId
        ));
    }

    public static function sameStaff(string $staffId): self
    {
        return new self(sprintf(
            'Cannot swap shifts between roster entries assigned to the same staff member [%s].',
            $staffId
        ));
    }

    public static function facilityMismatch(string $facilityA, string $facilityB): self
    {
        return new self(sprintf(
            'Cannot swap shifts across different facilities: [%s] vs [%s]. Shifts can only be swapped within the same facility.',
            $facilityA,
            $facilityB
        ));
    }

    public function errorCode(): string
    {
        return 'shift_swap_mismatch';
    }

    public function httpStatus(): int
    {
        return 422;
    }
}
