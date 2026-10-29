<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Reader;

use App\Domains\Theatre\Contracts\Dto\SurgicalCaseSummary;
use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Domains\Theatre\Domain\SurgicalCaseReader;
use App\Shared\Domain\NotFound;

final class EloquentSurgicalCaseReader implements SurgicalCaseReader
{
    public function summary(string $caseId): SurgicalCaseSummary
    {
        $case = SurgicalCase::query()->whereKey($caseId)->first()
            ?? throw NotFound::aggregate(SurgicalCase::class, $caseId);

        return $this->toSummary($case);
    }

    /**
     * @return list<SurgicalCaseSummary>
     */
    public function activeCases(string $facilityId): array
    {
        $cases = SurgicalCase::query()
            ->where('facility_id', $facilityId)
            ->whereIn('status', SurgicalCase::ACTIVE_STATUSES)
            ->get();

        $summaries = [];
        foreach ($cases as $case) {
            $summaries[] = $this->toSummary($case);
        }

        return $summaries;
    }

    private function toSummary(SurgicalCase $case): SurgicalCaseSummary
    {
        return new SurgicalCaseSummary(
            caseId: (string) $case->id,
            facilityId: (string) $case->facility_id,
            patientId: (string) $case->patient_id,
            encounterId: (string) $case->encounter_id,
            operatingRoomId: $case->operating_room_id !== null ? (string) $case->operating_room_id : null,
            caseNumber: (string) $case->case_number,
            priority: (string) $case->priority,
            scheduledFor: $case->scheduled_for?->toISOString(),
            plannedProcedure: $case->planned_procedure,
            actualProcedure: $case->actual_procedure,
            indication: $case->indication,
            findings: $case->findings,
            outcome: $case->outcome,
            complications: $case->complications,
            safetyChecklist: $case->safety_checklist,
            recoveryDestinationType: $case->recovery_destination_type,
            status: (string) $case->status,
            createdAt: $case->created_at?->toISOString(),
            updatedAt: $case->updated_at?->toISOString(),
        );
    }
}
