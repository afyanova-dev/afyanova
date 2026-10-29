<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Encounter does not meet eligibility criteria for a new surgical case
 * (ADR-150 D-H). Requires patient ACTIVE (incl. provisional) and encounter
 * status IN {ARRIVED, ACTIVE}. Maps to HTTP 409.
 */
final class EncounterNotEligibleForSurgicalCase extends DomainException
{
    public static function status(string $encounterId, string $status): self
    {
        return new self(sprintf(
            'Encounter [%s] is [%s] — surgical case creation requires ARRIVED or ACTIVE.',
            $encounterId,
            $status,
        ));
    }

    public static function facilityMismatch(string $encounterId, string $encounterFacilityId, string $requestedFacilityId): self
    {
        return new self(sprintf(
            'Encounter [%s] facility [%s] does not match requested facility [%s].',
            $encounterId,
            $encounterFacilityId,
            $requestedFacilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'encounter_not_eligible_for_surgical_case';
    }
}
