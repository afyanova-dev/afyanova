<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * CareEpisodeEncounter status transition was illegal
 * (26-care-coordination-domain.md §8; ADR-153 D-C) — e.g. detaching a link
 * that is already DETACHED. Maps to HTTP 409.
 */
final class IllegalCareEpisodeEncounterTransition extends DomainException
{
    public static function fromStatus(string $action, string $currentStatus): self
    {
        return new self(sprintf(
            'Cannot perform action [%s] on episode-encounter link currently in status [%s].',
            $action,
            $currentStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_care_episode_encounter_transition';
    }
}
