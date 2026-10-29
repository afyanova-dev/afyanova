<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Infrastructure\Reader;

use App\Domains\CareCoordination\Contracts\Dto\CareEpisodeEncounterSummary;
use App\Domains\CareCoordination\Contracts\Dto\CareEpisodeSummary;
use App\Domains\CareCoordination\Domain\CareEpisodeReader;
use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Domains\CareCoordination\Domain\Model\CareEpisodeEncounter;
use App\Shared\Domain\NotFound;

final class EloquentCareEpisodeReader implements CareEpisodeReader
{
    public function summary(string $careEpisodeId): CareEpisodeSummary
    {
        $episode = CareEpisode::query()->whereKey($careEpisodeId)->first()
            ?? throw NotFound::aggregate(CareEpisode::class, $careEpisodeId);

        return $this->toSummary($episode);
    }

    /**
     * @return list<CareEpisodeSummary>
     */
    public function forPatient(string $patientId): array
    {
        $episodes = CareEpisode::query()
            ->where('patient_id', $patientId)
            ->orderByDesc('opened_at')
            ->get();

        $out = [];
        foreach ($episodes as $episode) {
            $out[] = $this->toSummary($episode);
        }

        return $out;
    }

    /**
     * @return list<CareEpisodeEncounterSummary>
     */
    public function encountersForEpisode(string $careEpisodeId): array
    {
        $rows = CareEpisodeEncounter::query()
            ->where('care_episode_id', $careEpisodeId)
            ->orderByRaw("status = 'LINKED' desc")
            ->orderBy('added_at')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->toLinkSummary($row);
        }

        return $out;
    }

    private function toSummary(CareEpisode $episode): CareEpisodeSummary
    {
        return new CareEpisodeSummary(
            careEpisodeId: (string) $episode->id,
            facilityId: (string) $episode->facility_id,
            patientId: (string) $episode->patient_id,
            type: (string) $episode->type,
            subtype: $episode->subtype,
            status: (string) $episode->status,
            isOverride: $episode->override_group !== null,
            createdByUserId: (string) $episode->created_by_user_id,
            openedAt: $episode->opened_at?->toISOString(),
            closedAt: $episode->closed_at?->toISOString(),
            encounters: $this->encountersForEpisode((string) $episode->id),
            createdAt: $episode->created_at?->toISOString(),
            updatedAt: $episode->updated_at?->toISOString(),
        );
    }

    private function toLinkSummary(CareEpisodeEncounter $row): CareEpisodeEncounterSummary
    {
        return new CareEpisodeEncounterSummary(
            careEpisodeEncounterId: (string) $row->id,
            careEpisodeId: (string) $row->care_episode_id,
            encounterId: (string) $row->encounter_id,
            patientId: (string) $row->patient_id,
            role: $row->role,
            status: (string) $row->status,
            addedReason: $row->added_reason,
            addedByUserId: (string) $row->added_by_user_id,
            addedAt: $row->added_at?->toISOString(),
            detachReason: $row->detach_reason,
            detachedByUserId: $row->detached_by_user_id,
            detachedAt: $row->detached_at?->toISOString(),
        );
    }
}
