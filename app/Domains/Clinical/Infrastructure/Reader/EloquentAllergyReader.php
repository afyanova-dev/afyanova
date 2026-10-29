<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\AllergySummary;
use App\Domains\Clinical\Domain\AllergyReader;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see AllergyReader} (IC §6.3; ADR-141 D-N).
 *
 * >>> Q7 (ADR-141 D-M): allergy READS are TENANT-WIDE. Unlike
 *     `EloquentProblemReader::forPatient`, there is NO `$inScopeFacilityIds`
 *     parameter and NO `whereIn('facility_id', …)` filter, and
 *     `GetAllergyHandler` does NO originating-facility refinement. RLS still
 *     confines every row to the caller's tenant. Do NOT add a facility filter. <<<
 */
final class EloquentAllergyReader implements AllergyReader
{
    public function summary(string $allergyId): AllergySummary
    {
        $allergy = Allergy::query()->whereKey($allergyId)->first()
            ?? throw NotFound::aggregate(Allergy::class, $allergyId);

        return $this->toSummary($allergy);
    }

    public function forPatient(string $patientId, ?string $clinicalStatus, ?string $verificationStatus): array
    {
        $query = Allergy::query()->where('patient_id', $patientId);

        if ($clinicalStatus !== null) {
            $query->where('clinical_status', $clinicalStatus);
        }

        if ($verificationStatus !== null) {
            $query->where('verification_status', $verificationStatus);
        }

        $summaries = $query
            ->orderBy('category')
            ->orderBy('substance_name')
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Allergy $allergy): AllergySummary => $this->toSummary($allergy))
            ->all();

        return array_values($summaries);
    }

    private function toSummary(Allergy $allergy): AllergySummary
    {
        return new AllergySummary(
            allergyId: (string) $allergy->id,
            patientId: (string) $allergy->patient_id,
            encounterId: (string) $allergy->encounter_id,
            facilityId: (string) $allergy->facility_id,
            authorUserId: (string) $allergy->author_user_id,
            authoredContext: $allergy->authored_context,
            category: (string) $allergy->category,
            substanceName: (string) $allergy->substance_name,
            substanceCode: $allergy->substance_code,
            substanceCodeSystem: $allergy->substance_code_system,
            reactionDescription: $allergy->reaction_description,
            severity: $allergy->severity,
            clinicalStatus: (string) $allergy->clinical_status,
            verificationStatus: (string) $allergy->verification_status,
            onsetDate: $allergy->onset_date?->toDateString(),
            resolvedAt: $allergy->resolved_at?->toISOString(),
            occurredAt: (string) $allergy->occurred_at->toISOString(),
            recordedAt: (string) $allergy->recorded_at->toISOString(),
        );
    }
}
