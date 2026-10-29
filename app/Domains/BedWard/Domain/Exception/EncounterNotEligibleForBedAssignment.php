<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Encounter does not meet the eligibility criteria for bed assignment/transfer
 * (24-bed-and-ward-management-domain.md §12; ADR-148 D-F).
 * Requires status == 'ACTIVE' AND encounterType in {'INPATIENT', 'MATERNITY'}.
 * Maps to HTTP 409.
 */
final class EncounterNotEligibleForBedAssignment extends DomainException
{
    public static function status(string $encounterId, string $status): self
    {
        return new self(sprintf(
            'Encounter [%s] is [%s] — bed assignment requires status ACTIVE.',
            $encounterId,
            $status,
        ));
    }

    public static function type(string $encounterId, string $type): self
    {
        return new self(sprintf(
            'Encounter [%s] has type [%s] — bed assignment requires INPATIENT or MATERNITY.',
            $encounterId,
            $type,
        ));
    }

    public static function facilityMismatch(string $encounterId, string $encounterFacilityId, string $bedFacilityId): self
    {
        return new self(sprintf(
            'Encounter [%s] facility [%s] does not match bed facility [%s].',
            $encounterId,
            $encounterFacilityId,
            $bedFacilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'encounter_not_eligible_for_bed_assignment';
    }
}
