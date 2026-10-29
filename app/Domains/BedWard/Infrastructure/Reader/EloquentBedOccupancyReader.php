<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Infrastructure\Reader;

use App\Domains\BedWard\Contracts\Dto\BedOccupancyRef;
use App\Domains\BedWard\Contracts\Dto\BedOccupancySummary;
use App\Domains\BedWard\Domain\BedOccupancyReader;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Shared\Domain\NotFound;

final class EloquentBedOccupancyReader implements BedOccupancyReader
{
    public function summary(string $occupancyId): BedOccupancySummary
    {
        $occ = BedOccupancy::query()->whereKey($occupancyId)->first()
            ?? throw NotFound::aggregate(BedOccupancy::class, $occupancyId);

        return $this->toSummary($occ);
    }

    public function activeForEncounter(string $encounterId): ?BedOccupancyRef
    {
        $occ = BedOccupancy::query()
            ->where('encounter_id', $encounterId)
            ->where('status', BedOccupancy::STATUS_ACTIVE)
            ->first();

        if ($occ === null) {
            return null;
        }

        $bed = Bed::query()->whereKey($occ->bed_id)->first();
        $wardId = $bed !== null ? (string) $bed->ward_id : '';

        return new BedOccupancyRef(
            occupancyId: (string) $occ->id,
            bedId: (string) $occ->bed_id,
            wardId: $wardId,
            facilityId: (string) $occ->facility_id,
            encounterId: (string) $occ->encounter_id,
            patientId: (string) $occ->patient_id,
            startedAt: $occ->occurred_at?->toISOString() ?? '',
        );
    }

    /**
     * @return array{current: BedOccupancySummary|null, history: list<BedOccupancySummary>}
     */
    public function currentAndHistoryForEncounter(string $encounterId): array
    {
        $records = BedOccupancy::query()
            ->where('encounter_id', $encounterId)
            ->orderBy('occurred_at', 'desc')
            ->get();

        $current = null;
        $history = [];

        foreach ($records as $record) {
            $summary = $this->toSummary($record);
            if ($record->status === BedOccupancy::STATUS_ACTIVE && $current === null) {
                $current = $summary;
            }
            $history[] = $summary;
        }

        return [
            'current' => $current,
            'history' => $history,
        ];
    }

    /**
     * @return list<BedOccupancySummary>
     */
    public function historyForBed(string $bedId): array
    {
        $records = BedOccupancy::query()
            ->where('bed_id', $bedId)
            ->orderBy('occurred_at', 'desc')
            ->get();

        $history = [];
        foreach ($records as $record) {
            $history[] = $this->toSummary($record);
        }

        return $history;
    }

    private function toSummary(BedOccupancy $occ): BedOccupancySummary
    {
        return new BedOccupancySummary(
            occupancyId: (string) $occ->id,
            facilityId: (string) $occ->facility_id,
            bedId: (string) $occ->bed_id,
            encounterId: (string) $occ->encounter_id,
            patientId: (string) $occ->patient_id,
            assignedByUserId: (string) $occ->assigned_by_user_id,
            status: (string) $occ->status,
            endReason: $occ->end_reason !== null ? (string) $occ->end_reason : null,
            occurredAt: $occ->occurred_at?->toISOString() ?? '',
            endedAt: $occ->ended_at?->toISOString(),
            recordedAt: $occ->recorded_at?->toISOString() ?? '',
        );
    }
}
