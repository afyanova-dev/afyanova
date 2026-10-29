<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Reader;

use App\Domains\Theatre\Contracts\Dto\SurgicalProcedureSummary;
use App\Domains\Theatre\Domain\Model\SurgicalProcedure;
use App\Domains\Theatre\Domain\SurgicalProcedureReader;
use App\Shared\Domain\NotFound;

final class EloquentSurgicalProcedureReader implements SurgicalProcedureReader
{
    /**
     * @return list<SurgicalProcedureSummary>
     */
    public function forCase(string $caseId): array
    {
        $rows = SurgicalProcedure::query()
            ->where('case_id', $caseId)
            ->orderBy('sequence')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->toSummary($row);
        }

        return $out;
    }

    public function summary(string $surgicalProcedureId): SurgicalProcedureSummary
    {
        $row = SurgicalProcedure::query()->whereKey($surgicalProcedureId)->first()
            ?? throw NotFound::aggregate(SurgicalProcedure::class, $surgicalProcedureId);

        return $this->toSummary($row);
    }

    private function toSummary(SurgicalProcedure $row): SurgicalProcedureSummary
    {
        return new SurgicalProcedureSummary(
            surgicalProcedureId: (string) $row->id,
            facilityId: (string) $row->facility_id,
            caseId: (string) $row->case_id,
            patientId: (string) $row->patient_id,
            encounterId: (string) $row->encounter_id,
            sequence: (int) $row->sequence,
            plannedDescription: $row->planned_description,
            actualDescription: $row->actual_description,
            startedAt: $row->started_at?->toISOString(),
            endedAt: $row->ended_at?->toISOString(),
            primarySurgeonUserId: $row->primary_surgeon_user_id,
            outcome: $row->outcome,
            findings: $row->findings,
            complications: $row->complications,
            status: (string) $row->status,
            createdAt: $row->created_at?->toISOString(),
            updatedAt: $row->updated_at?->toISOString(),
        );
    }
}
