<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Reader;

use App\Domains\Radiology\Contracts\Dto\ImagingStudySummary;
use App\Domains\Radiology\Domain\ImagingStudyReader;
use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Shared\Domain\NotFound;

final class EloquentImagingStudyReader implements ImagingStudyReader
{
    public function summary(string $studyId): ImagingStudySummary
    {
        $study = ImagingStudy::query()->whereKey($studyId)->first()
            ?? throw NotFound::aggregate(ImagingStudy::class, $studyId);

        return $this->toSummary($study);
    }

    /**
     * @return list<ImagingStudySummary>
     */
    public function forOrder(string $orderId): array
    {
        $studies = ImagingStudy::query()
            ->where('order_id', $orderId)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        return array_values($studies->map(fn (ImagingStudy $s): ImagingStudySummary => $this->toSummary($s))->all());
    }

    /**
     * @param  list<string>|null  $statuses
     * @return list<ImagingStudySummary>
     */
    public function worklistForFacility(string $facilityId, ?array $statuses = null): array
    {
        $query = ImagingStudy::query()->where('facility_id', $facilityId);

        if ($statuses !== null && $statuses !== []) {
            $query->whereIn('status', $statuses);
        } else {
            $query->whereIn('status', ImagingStudy::NON_TERMINAL_STATUSES);
        }

        $studies = $query->orderByDesc('recorded_at')->get();

        return array_values($studies->map(fn (ImagingStudy $s): ImagingStudySummary => $this->toSummary($s))->all());
    }

    private function toSummary(ImagingStudy $study): ImagingStudySummary
    {
        return new ImagingStudySummary(
            imagingStudyId: (string) $study->id,
            facilityId: (string) $study->facility_id,
            orderId: (string) $study->order_id,
            orderItemId: (string) $study->order_item_id,
            patientId: (string) $study->patient_id,
            encounterId: (string) $study->encounter_id,
            accessionNumber: (string) $study->accession_number,
            modalityCode: (string) $study->modality_code,
            serviceCode: (string) $study->service_code,
            bodySite: $study->body_site,
            laterality: $study->laterality,
            status: (string) $study->status,
            scheduledFor: $study->scheduled_for?->toIso8601String(),
            appointmentRef: $study->appointment_ref,
            equipmentRef: $study->equipment_ref,
            verifiedByUserId: $study->verified_by_user_id,
            verifiedAt: $study->verified_at?->toIso8601String(),
            performedByUserId: $study->performed_by_user_id,
            startedAt: $study->started_at?->toIso8601String(),
            completedAt: $study->completed_at?->toIso8601String(),
            outcomeStatusCode: $study->outcome_status_code,
            outcomeDetail: $study->outcome_detail,
            repeatOfStudyId: $study->repeat_of_study_id,
        );
    }
}
