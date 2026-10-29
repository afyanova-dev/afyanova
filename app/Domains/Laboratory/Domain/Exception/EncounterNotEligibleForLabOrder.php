<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateLabOrder` named an `encounterId` whose encounter is not in an
 * orderable state (ADR-157 D-I). Requires status IN {ARRIVED, ACTIVE,
 * COMPLETED} — the `CreateClinicalNote` / ADR-135 precedent (a lab order at
 * discharge with results pending is plausible). Maps to HTTP 409.
 */
final class EncounterNotEligibleForLabOrder extends DomainException
{
    public static function status(string $encounterId, string $status): self
    {
        return new self(sprintf(
            'Encounter [%s] is [%s] — laboratory ordering requires ARRIVED, ACTIVE or COMPLETED.',
            $encounterId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'encounter_not_eligible_for_lab_order';
    }
}
