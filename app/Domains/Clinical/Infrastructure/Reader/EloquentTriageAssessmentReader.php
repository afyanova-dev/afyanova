<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\TriageAssessmentSummary;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Domains\Clinical\Domain\TriageAssessmentReader;
use App\Shared\Domain\NotFound;

final class EloquentTriageAssessmentReader implements TriageAssessmentReader
{
    public function summary(string $triageAssessmentId): TriageAssessmentSummary
    {
        $assessment = TriageAssessment::query()->whereKey($triageAssessmentId)->first()
            ?? throw NotFound::aggregate(TriageAssessment::class, $triageAssessmentId);

        return $this->toSummary($assessment);
    }

    public function forEncounter(string $encounterId, array $inScopeFacilityIds, bool $currentOnly = false): array
    {
        if ($inScopeFacilityIds === []) {
            return [];
        }

        $query = TriageAssessment::query()
            ->where('encounter_id', $encounterId)
            ->whereIn('facility_id', $inScopeFacilityIds);

        if ($currentOnly) {
            $query->whereNull('superseded_at');
        }

        $summaries = $query
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (TriageAssessment $assessment): TriageAssessmentSummary => $this->toSummary($assessment))
            ->all();

        return array_values($summaries);
    }

    public function recordedEncounterIds(array $encounterIds, array $facilityIds): array
    {
        if ($encounterIds === [] || $facilityIds === []) {
            return [];
        }

        $encounterIds = TriageAssessment::query()
            ->whereIn('encounter_id', $encounterIds)
            ->whereIn('facility_id', $facilityIds)
            ->whereNull('superseded_at')
            ->distinct()
            ->pluck('encounter_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        return array_values($encounterIds);
    }

    private function toSummary(TriageAssessment $assessment): TriageAssessmentSummary
    {
        return new TriageAssessmentSummary(
            triageAssessmentId: (string) $assessment->id,
            patientId: (string) $assessment->patient_id,
            encounterId: (string) $assessment->encounter_id,
            facilityId: (string) $assessment->facility_id,
            authorUserId: (string) $assessment->author_user_id,
            authoredContext: $assessment->authored_context,
            chiefComplaint: (string) $assessment->chief_complaint,
            triagePriority: (string) $assessment->triage_priority,
            acuity: $assessment->acuity,
            consciousness: $assessment->consciousness,
            sourceVitalsObservationId: $assessment->source_vitals_observation_id,
            occurredAt: (string) $assessment->occurred_at->toISOString(),
            recordedAt: (string) $assessment->recorded_at->toISOString(),
            supersedesTriageAssessmentId: $assessment->supersedes_triage_assessment_id,
            supersededAt: $assessment->superseded_at?->toISOString(),
            supersededByTriageAssessmentId: $assessment->superseded_by_triage_assessment_id,
            correctionReason: $assessment->reason,
        );
    }
}
