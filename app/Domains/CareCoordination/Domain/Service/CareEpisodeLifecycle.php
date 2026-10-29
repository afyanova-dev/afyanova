<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Service;

use App\Domains\CareCoordination\Domain\Exception\IllegalCareEpisodeEncounterTransition;
use App\Domains\CareCoordination\Domain\Exception\IllegalCareEpisodeTransition;
use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Domains\CareCoordination\Domain\Model\CareEpisodeEncounter;

/**
 * CareEpisode and CareEpisodeEncounter lifecycle rules
 * (26-care-coordination-domain.md §8; ADR-153 D-C — one service for both
 * entities, the ReferralLifecycle multi-entity precedent). Pure domain logic,
 * no I/O.
 */
final class CareEpisodeLifecycle
{
    // --- CareEpisode --------------------------------------------------

    public function assertCanClose(string $status): void
    {
        if ($status !== CareEpisode::STATUS_OPEN) {
            throw IllegalCareEpisodeTransition::fromStatus('CloseCareEpisode', $status);
        }
    }

    public function assertCanTransferOut(string $status): void
    {
        if ($status !== CareEpisode::STATUS_OPEN) {
            throw IllegalCareEpisodeTransition::fromStatus('MarkEpisodeTransferredOut', $status);
        }
    }

    /**
     * A new encounter link may only be added while the episode is OPEN
     * (§7.8 keeps the lifecycles independent, but a closed episode does not
     * gain new members).
     */
    public function assertCanLinkEncounter(string $episodeStatus): void
    {
        if ($episodeStatus !== CareEpisode::STATUS_OPEN) {
            throw IllegalCareEpisodeTransition::fromStatus('LinkEncounterToEpisode', $episodeStatus);
        }
    }

    // --- CareEpisodeEncounter ---------------------------------------------

    public function assertCanDetach(string $linkStatus): void
    {
        if ($linkStatus !== CareEpisodeEncounter::STATUS_LINKED) {
            throw IllegalCareEpisodeEncounterTransition::fromStatus('DetachEncounterFromEpisode', $linkStatus);
        }
    }
}
