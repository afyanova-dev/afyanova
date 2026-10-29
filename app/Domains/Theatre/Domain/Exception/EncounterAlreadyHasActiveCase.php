<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Encounter already has an active (READY|IN_PROGRESS) surgical case
 * (17-theatre-domain.md §299 Invariant 13; ADR-150 D-E). Maps to HTTP 409.
 */
final class EncounterAlreadyHasActiveCase extends DomainException
{
    public static function forEncounter(string $encounterId): self
    {
        return new self(sprintf(
            'Encounter [%s] already has an active surgical case.',
            $encounterId,
        ));
    }

    public function errorCode(): string
    {
        return 'encounter_already_has_active_case';
    }
}
