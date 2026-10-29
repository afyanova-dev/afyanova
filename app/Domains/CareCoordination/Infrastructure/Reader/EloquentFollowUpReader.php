<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Infrastructure\Reader;

use App\Domains\CareCoordination\Contracts\Dto\FollowUpSummary;
use App\Domains\CareCoordination\Domain\FollowUpReader;
use App\Domains\CareCoordination\Domain\Model\FollowUp;

final class EloquentFollowUpReader implements FollowUpReader
{
    /**
     * @return list<FollowUpSummary>
     */
    public function dueForFacility(string $facilityId, string $from, string $to): array
    {
        $rows = FollowUp::query()
            ->where('facility_id', $facilityId)
            ->where('status', FollowUp::STATUS_PENDING)
            ->where('due_from', '<=', $to)
            ->where('due_to', '>=', $from)
            ->orderBy('due_from')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = new FollowUpSummary(
                followUpId: (string) $row->id,
                facilityId: (string) $row->facility_id,
                patientId: (string) $row->patient_id,
                sourceEncounterId: (string) $row->source_encounter_id,
                dueFrom: $row->due_from?->toDateString() ?? '',
                dueTo: $row->due_to?->toDateString() ?? '',
                reason: $row->reason,
                instructions: $row->instructions,
                bookedAppointmentId: $row->booked_appointment_id,
                resultingEncounterId: $row->resulting_encounter_id,
                status: (string) $row->status,
                outcomeRecordedAt: $row->outcome_recorded_at?->toISOString(),
                createdAt: $row->created_at?->toISOString(),
                updatedAt: $row->updated_at?->toISOString(),
            );
        }

        return $out;
    }
}
