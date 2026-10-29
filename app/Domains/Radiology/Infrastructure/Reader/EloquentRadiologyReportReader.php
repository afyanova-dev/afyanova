<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Reader;

use App\Domains\Radiology\Contracts\Dto\RadiologyReportAmendmentSummary;
use App\Domains\Radiology\Contracts\Dto\RadiologyReportSummary;
use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Domains\Radiology\Domain\Model\RadiologyReportAmendment;
use App\Domains\Radiology\Domain\RadiologyReportReader;
use App\Shared\Domain\NotFound;

final class EloquentRadiologyReportReader implements RadiologyReportReader
{
    public function summary(string $reportId): RadiologyReportSummary
    {
        $report = RadiologyReport::query()->whereKey($reportId)->first()
            ?? throw NotFound::aggregate(RadiologyReport::class, $reportId);

        return $this->toSummary($report);
    }

    public function forStudy(string $studyId): RadiologyReportSummary
    {
        $report = RadiologyReport::query()->where('study_id', $studyId)->first()
            ?? throw NotFound::aggregate(RadiologyReport::class, $studyId);

        return $this->toSummary($report);
    }

    /**
     * @return list<RadiologyReportSummary>
     */
    public function pendingForFacility(string $facilityId): array
    {
        $reports = RadiologyReport::query()
            ->where('facility_id', $facilityId)
            ->whereIn('status', [
                RadiologyReport::STATUS_DRAFT,
                RadiologyReport::STATUS_REVIEWED,
                RadiologyReport::STATUS_VERIFIED,
            ])
            ->orderBy('authored_at')
            ->orderBy('id')
            ->get();

        return array_values($reports->map(fn (RadiologyReport $r): RadiologyReportSummary => $this->toSummary($r))->all());
    }

    /**
     * @return list<RadiologyReportAmendmentSummary>
     */
    public function amendmentsFor(string $reportId): array
    {
        $rows = RadiologyReportAmendment::query()
            ->where('report_id', $reportId)
            ->orderBy('sequence')
            ->get();

        return array_values($rows->map(fn (RadiologyReportAmendment $a): RadiologyReportAmendmentSummary => new RadiologyReportAmendmentSummary(
            amendmentId: (string) $a->id,
            reportId: (string) $a->report_id,
            sequence: (int) $a->sequence,
            amendmentType: (string) $a->amendment_type,
            previousStatus: (string) $a->previous_status,
            newStatus: (string) $a->new_status,
            previousFindingsText: $a->previous_findings_text,
            previousImpressionText: $a->previous_impression_text,
            previousRecommendationsText: $a->previous_recommendations_text,
            newFindingsText: $a->new_findings_text,
            newImpressionText: $a->new_impression_text,
            newRecommendationsText: $a->new_recommendations_text,
            reason: (string) $a->reason,
            amendedByUserId: (string) $a->amended_by_user_id,
            amendedAt: $a->amended_at?->toIso8601String(),
        ))->all());
    }

    private function toSummary(RadiologyReport $report): RadiologyReportSummary
    {
        return new RadiologyReportSummary(
            reportId: (string) $report->id,
            facilityId: (string) $report->facility_id,
            studyId: (string) $report->study_id,
            orderId: (string) $report->order_id,
            orderItemId: (string) $report->order_item_id,
            patientId: (string) $report->patient_id,
            encounterId: (string) $report->encounter_id,
            accessionNumber: (string) $report->accession_number,
            modalityCode: (string) $report->modality_code,
            serviceCode: (string) $report->service_code,
            clinicalIndicationText: $report->clinical_indication_text,
            techniqueText: $report->technique_text,
            findingsText: $report->findings_text,
            impressionText: $report->impression_text,
            recommendationsText: $report->recommendations_text,
            status: (string) $report->status,
            releaseHoldReason: $report->release_hold_reason,
            amendmentCount: (int) $report->amendment_count,
            cancellationReason: $report->cancellation_reason,
            criticalFindingFlagged: (bool) $report->critical_finding_flagged,
            authoredByUserId: (string) $report->authored_by_user_id,
            authoredAt: $report->authored_at?->toIso8601String(),
            reviewedByUserId: $report->reviewed_by_user_id,
            reviewedAt: $report->reviewed_at?->toIso8601String(),
            verifiedByUserId: $report->verified_by_user_id,
            verifiedAt: $report->verified_at?->toIso8601String(),
            releasedByUserId: $report->released_by_user_id,
            releasedAt: $report->released_at?->toIso8601String(),
        );
    }
}
