<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateImagingOrder` named an `encounterId` whose encounter is not in an
 * orderable state (ADR-165 D-J). Requires status IN {ARRIVED, ACTIVE,
 * COMPLETED} — the `CreateClinicalNote` / `CreateLabOrder` precedent (an
 * imaging order at discharge with a report pending is plausible). Maps to
 * HTTP 409.
 */
final class EncounterNotEligibleForImagingOrder extends DomainException
{
    public static function status(string $encounterId, string $status): self
    {
        return new self(sprintf(
            'Encounter [%s] is [%s] — imaging ordering requires ARRIVED, ACTIVE or COMPLETED.',
            $encounterId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'encounter_not_eligible_for_imaging_order';
    }
}
