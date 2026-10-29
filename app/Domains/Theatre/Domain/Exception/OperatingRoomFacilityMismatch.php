<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AssignOperatingRoom` named a room at a different facility than the case
 * (17-theatre-domain.md §297 Invariant 2; ADR-150 D-C). Maps to HTTP 409.
 */
final class OperatingRoomFacilityMismatch extends DomainException
{
    public static function forCase(string $caseId, string $caseFacilityId, string $roomFacilityId): self
    {
        return new self(sprintf(
            'SurgicalCase [%s] facility [%s] does not match operating room facility [%s].',
            $caseId,
            $caseFacilityId,
            $roomFacilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'operating_room_facility_mismatch';
    }
}
