<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `LinkEncounterToEpisode` named an encounter whose patient is not the
 * episode's patient (ADR-153 D-I — the `ReferralContextMismatch` precedent).
 * The episode and the encounter must be about the same patient. Maps to
 * HTTP 409.
 */
final class CareEpisodeContextMismatch extends DomainException
{
    public static function patientEncounter(string $episodePatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'Care episode is for patient [%s] but encounter [%s] belongs to patient [%s].',
            $episodePatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'care_episode_context_mismatch';
    }
}
