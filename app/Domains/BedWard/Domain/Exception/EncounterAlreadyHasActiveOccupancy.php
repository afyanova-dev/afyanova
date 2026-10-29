<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Encounter already has an active occupancy (24-bed-and-ward-management-domain.md §7 Inv 4;
 * ADR-148 D-C). Maps to HTTP 409.
 */
final class EncounterAlreadyHasActiveOccupancy extends DomainException
{
    public static function forEncounter(string $encounterId): self
    {
        return new self(sprintf(
            'Encounter [%s] already has an active bed occupancy (Invariant 4; ADR-148 D-C).',
            $encounterId,
        ));
    }

    public function errorCode(): string
    {
        return 'encounter_already_has_active_occupancy';
    }
}
