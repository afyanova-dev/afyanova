<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `LinkEncounterToEpisode` was attempted for an (episode, encounter) pair
 * that already has an active LINKED row (ADR-153 D-D — the
 * `care_episode_encounters_one_active_link` partial unique index). Maps to
 * HTTP 409.
 */
final class EncounterAlreadyLinkedToEpisode extends DomainException
{
    public static function forPair(string $episodeId, string $encounterId): self
    {
        return new self(sprintf(
            'Encounter [%s] is already linked to care episode [%s].',
            $encounterId,
            $episodeId,
        ));
    }

    public function errorCode(): string
    {
        return 'encounter_already_linked_to_episode';
    }
}
