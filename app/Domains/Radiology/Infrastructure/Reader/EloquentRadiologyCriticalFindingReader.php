<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Reader;

use App\Domains\Radiology\Contracts\Dto\RadiologyCriticalFindingSummary;
use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;
use App\Domains\Radiology\Domain\RadiologyCriticalFindingReader;
use App\Shared\Domain\NotFound;

final class EloquentRadiologyCriticalFindingReader implements RadiologyCriticalFindingReader
{
    public function summary(string $criticalFindingId): RadiologyCriticalFindingSummary
    {
        $finding = RadiologyCriticalFinding::query()->whereKey($criticalFindingId)->first()
            ?? throw NotFound::aggregate(RadiologyCriticalFinding::class, $criticalFindingId);

        return $this->toSummary($finding);
    }

    /**
     * @return list<RadiologyCriticalFindingSummary>
     */
    public function pendingForFacility(string $facilityId): array
    {
        $findings = RadiologyCriticalFinding::query()
            ->where('facility_id', $facilityId)
            ->where('status', RadiologyCriticalFinding::STATUS_INITIATED)
            ->orderBy('identified_at')
            ->orderBy('id')
            ->get();

        return array_values($findings->map(fn (RadiologyCriticalFinding $f): RadiologyCriticalFindingSummary => $this->toSummary($f))->all());
    }

    private function toSummary(RadiologyCriticalFinding $finding): RadiologyCriticalFindingSummary
    {
        return new RadiologyCriticalFindingSummary(
            criticalFindingId: (string) $finding->id,
            facilityId: (string) $finding->facility_id,
            reportId: (string) $finding->report_id,
            studyId: (string) $finding->study_id,
            orderId: (string) $finding->order_id,
            patientId: (string) $finding->patient_id,
            accessionNumber: (string) $finding->accession_number,
            modalityCode: (string) $finding->modality_code,
            findingSummary: (string) $finding->finding_summary,
            severity: (string) $finding->severity,
            status: (string) $finding->status,
            identifiedByUserId: (string) $finding->identified_by_user_id,
            identifiedAt: $finding->identified_at?->toIso8601String(),
            acknowledgedByUserId: $finding->acknowledged_by_user_id,
            acknowledgedAt: $finding->acknowledged_at?->toIso8601String(),
            acknowledgementNote: $finding->acknowledgement_note,
        );
    }
}
