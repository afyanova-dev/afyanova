<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Reader;

use App\Domains\Laboratory\Contracts\Dto\LabSpecimenSummary;
use App\Domains\Laboratory\Domain\LabSpecimenReader;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Shared\Domain\NotFound;

final class EloquentLabSpecimenReader implements LabSpecimenReader
{
    public function summary(string $specimenId): LabSpecimenSummary
    {
        $specimen = LabSpecimen::query()->whereKey($specimenId)->first()
            ?? throw NotFound::aggregate(LabSpecimen::class, $specimenId);

        return $this->toSummary($specimen);
    }

    /**
     * @return list<LabSpecimenSummary>
     */
    public function forOrder(string $orderId): array
    {
        $specimens = LabSpecimen::query()
            ->where('order_id', $orderId)
            ->orderBy('collected_at')
            ->orderBy('id')
            ->get();

        return array_values($specimens->map(fn (LabSpecimen $s): LabSpecimenSummary => $this->toSummary($s))->all());
    }

    private function toSummary(LabSpecimen $specimen): LabSpecimenSummary
    {
        return new LabSpecimenSummary(
            specimenId: (string) $specimen->id,
            orderId: (string) $specimen->order_id,
            patientId: (string) $specimen->patient_id,
            facilityId: (string) $specimen->facility_id,
            accessionNumber: (string) $specimen->accession_number,
            specimenType: (string) $specimen->specimen_type,
            containerType: $specimen->container_type,
            collectionLocation: $specimen->collection_location,
            collectedByUserId: (string) $specimen->collected_by_user_id,
            collectedAt: $specimen->collected_at?->toIso8601String(),
            receivedByUserId: $specimen->received_by_user_id,
            receivedAt: $specimen->received_at?->toIso8601String(),
            status: (string) $specimen->status,
            rejectionReasonCode: $specimen->rejection_reason_code,
            rejectionDetail: $specimen->rejection_detail,
            recollectionRequestedAt: $specimen->recollection_requested_at?->toIso8601String(),
            recollectionOfSpecimenId: $specimen->recollection_of_specimen_id,
        );
    }
}
