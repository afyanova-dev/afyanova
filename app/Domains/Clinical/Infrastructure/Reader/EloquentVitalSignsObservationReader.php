<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\VitalSignsObservationSummary;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\VitalSignsObservationReader;
use App\Shared\Domain\NotFound;

final class EloquentVitalSignsObservationReader implements VitalSignsObservationReader
{
    /**
     * @param  list<string>  $inScopeFacilityIds
     * @return list<VitalSignsObservationSummary>
     */
    public function forPatient(string $patientId, array $inScopeFacilityIds): array
    {
        if ($inScopeFacilityIds === []) {
            return [];
        }

        $summaries = VitalSignsObservation::query()
            ->where('patient_id', $patientId)
            ->whereIn('facility_id', $inScopeFacilityIds)
            ->orderByDesc('occurred_at')
            ->orderByDesc('recorded_at')
            ->get()
            ->map(fn (VitalSignsObservation $observation): VitalSignsObservationSummary => $this->toSummary($observation))
            ->all();

        return array_values($summaries);
    }

    public function summary(string $vitalSignsObservationId): VitalSignsObservationSummary
    {
        $observation = VitalSignsObservation::query()->whereKey($vitalSignsObservationId)->first()
            ?? throw NotFound::aggregate(VitalSignsObservation::class, $vitalSignsObservationId);

        return $this->toSummary($observation);
    }

    public function recordedEncounterIds(array $encounterIds, array $facilityIds): array
    {
        if ($encounterIds === [] || $facilityIds === []) {
            return [];
        }

        $encounterIds = VitalSignsObservation::query()
            ->whereIn('encounter_id', $encounterIds)
            ->whereIn('facility_id', $facilityIds)
            ->whereNull('superseded_at')
            ->distinct()
            ->pluck('encounter_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return array_values($encounterIds);
    }

    private function toSummary(VitalSignsObservation $observation): VitalSignsObservationSummary
    {
        return new VitalSignsObservationSummary(
            vitalSignsObservationId: (string) $observation->id,
            patientId: (string) $observation->patient_id,
            encounterId: (string) $observation->encounter_id,
            facilityId: (string) $observation->facility_id,
            authorUserId: (string) $observation->author_user_id,
            authoredContext: $observation->authored_context,
            temperature: $observation->temperature === null ? null : (float) $observation->temperature,
            heartRate: $observation->heart_rate,
            respiratoryRate: $observation->respiratory_rate,
            systolicBp: $observation->systolic_bp,
            diastolicBp: $observation->diastolic_bp,
            oxygenSaturation: $observation->oxygen_saturation,
            weight: $observation->weight === null ? null : (float) $observation->weight,
            height: $observation->height === null ? null : (float) $observation->height,
            painScore: $observation->pain_score,
            occurredAt: (string) $observation->occurred_at->toISOString(),
            recordedAt: (string) $observation->recorded_at->toISOString(),
            supersedesObservationId: $observation->supersedes_observation_id,
            supersededAt: $observation->superseded_at?->toISOString(),
            supersededByObservationId: $observation->superseded_by_observation_id,
            correctionReason: $observation->reason,
        );
    }
}
