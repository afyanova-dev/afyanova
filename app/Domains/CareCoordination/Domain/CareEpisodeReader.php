<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain;

use App\Domains\CareCoordination\Contracts\Dto\CareEpisodeEncounterSummary;
use App\Domains\CareCoordination\Contracts\Dto\CareEpisodeSummary;

/**
 * Read-side port for CareEpisode / CareEpisodeEncounter queries (ADR-153 D-F).
 */
interface CareEpisodeReader
{
    public function summary(string $careEpisodeId): CareEpisodeSummary;

    /**
     * @return list<CareEpisodeSummary>
     */
    public function forPatient(string $patientId): array;

    /**
     * The membership links for an episode, LINKED first, each in `added_at`
     * order (ADR-153 D-F — GetEpisodeEncounters).
     *
     * @return list<CareEpisodeEncounterSummary>
     */
    public function encountersForEpisode(string $careEpisodeId): array;
}
