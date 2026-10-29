<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Reader;

use App\Domains\Theatre\Contracts\Dto\SurgicalSpecimenSummary;
use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;
use App\Domains\Theatre\Domain\SurgicalSpecimenReader;
use App\Shared\Domain\NotFound;

final class EloquentSurgicalSpecimenReader implements SurgicalSpecimenReader
{
    public function summary(string $surgicalSpecimenId): SurgicalSpecimenSummary
    {
        $specimen = SurgicalSpecimen::query()->whereKey($surgicalSpecimenId)->first()
            ?? throw NotFound::aggregate(SurgicalSpecimen::class, $surgicalSpecimenId);

        return $this->toSummary($specimen);
    }

    /**
     * @return list<SurgicalSpecimenSummary>
     */
    public function forCase(string $caseId): array
    {
        $specimens = SurgicalSpecimen::query()
            ->where('case_id', $caseId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        return array_values($specimens->map(fn (SurgicalSpecimen $s): SurgicalSpecimenSummary => $this->toSummary($s))->all());
    }

    private function toSummary(SurgicalSpecimen $specimen): SurgicalSpecimenSummary
    {
        return new SurgicalSpecimenSummary(
            surgicalSpecimenId: (string) $specimen->id,
            facilityId: (string) $specimen->facility_id,
            caseId: (string) $specimen->case_id,
            patientId: (string) $specimen->patient_id,
            encounterId: (string) $specimen->encounter_id,
            sequence: (int) $specimen->sequence,
            specimenType: (string) $specimen->specimen_type,
            bodySite: $specimen->body_site,
            laterality: $specimen->laterality,
            description: $specimen->description,
            collectedByUserId: (string) $specimen->collected_by_user_id,
            collectedAt: $specimen->collected_at?->toIso8601String(),
            destinationLaboratory: $specimen->destination_laboratory,
            labAccessionRef: $specimen->lab_accession_ref,
            status: (string) $specimen->status,
            handedOverByUserId: $specimen->handed_over_by_user_id,
            handedOverAt: $specimen->handed_over_at?->toIso8601String(),
            receivedRecordedAt: $specimen->received_recorded_at?->toIso8601String(),
        );
    }
}
