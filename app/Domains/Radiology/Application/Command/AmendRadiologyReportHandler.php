<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Domains\Radiology\Domain\Model\RadiologyReportAmendment;
use App\Domains\Radiology\Domain\RadiologyReportRepository;
use App\Domains\Radiology\Domain\Service\RadiologyReportLifecycle;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `AmendRadiologyReport` (ADR-168 D-D). Appends the append-only
 * `rad_report_amendments` row (before/after + reason) and flips the report to
 * AMENDED via the aggregate.
 *
 * `getByIdForUpdate` — a 2nd amendment on an already-AMENDED report re-assigns
 * `status` to the same value; `amendment_count` keeps the aggregate dirty so
 * `save()` still runs the optimistic-lock UPDATE, and the FOR UPDATE lock
 * serializes concurrent amendments (the T.2 lesson).
 *
 * CORRECTION replaces the section text; ADDENDUM appends labelled supplementary
 * text to the current text.
 */
final class AmendRadiologyReportHandler
{
    public function __construct(
        private readonly RadiologyReportRepository $reports,
        private readonly RadiologyReportLifecycle $lifecycle,
    ) {}

    public function __invoke(AmendRadiologyReport $command): void
    {
        $report = $this->reports->getByIdForUpdate($command->reportId);

        $this->lifecycle->assertCanAmend((string) $report->status);

        $previousStatus = (string) $report->status;
        $previousFindings = $report->findings_text;
        $previousImpression = $report->impression_text;
        $previousRecommendations = $report->recommendations_text;

        $type = $command->normalisedType();

        $newFindings = self::apply($type, $previousFindings, $command->findingsText, 'FINDINGS');
        $newImpression = self::apply($type, $previousImpression, $command->impressionText, 'IMPRESSION');
        $newRecommendations = self::apply($type, $previousRecommendations, $command->recommendationsText, 'RECOMMENDATIONS');

        $report->amend([
            'findingsText' => $newFindings,
            'impressionText' => $newImpression,
            'recommendationsText' => $newRecommendations,
        ]);

        $sequence = $this->reports->nextAmendmentSequence($command->reportId);

        RadiologyReportAmendment::create([
            'id' => $command->amendmentId,
            'tenant_id' => $report->tenant_id,
            'facility_id' => $report->facility_id,
            'report_id' => $report->id,
            'sequence' => $sequence,
            'amendment_type' => $type === RadiologyReport::AMENDMENT_ADDENDUM
                ? RadiologyReportAmendment::TYPE_ADDENDUM
                : RadiologyReportAmendment::TYPE_CORRECTION,
            'previous_status' => $previousStatus,
            'new_status' => RadiologyReport::STATUS_AMENDED,
            'previous_findings_text' => $previousFindings,
            'previous_impression_text' => $previousImpression,
            'previous_recommendations_text' => $previousRecommendations,
            'new_findings_text' => $newFindings,
            'new_impression_text' => $newImpression,
            'new_recommendations_text' => $newRecommendations,
            'reason' => trim($command->reason),
            'amended_by_user_id' => $command->actorId,
            'amended_at' => CarbonImmutable::now(),
        ]);

        $this->reports->save($report);
    }

    private static function apply(string $type, ?string $current, ?string $supplied, string $label): ?string
    {
        $supplied = ($supplied !== null && trim($supplied) !== '') ? trim($supplied) : null;

        if ($type === RadiologyReport::AMENDMENT_CORRECTION) {
            // A correction that omits a section leaves it unchanged.
            return $supplied ?? $current;
        }

        // ADDENDUM — append the supplied text under a labelled separator.
        if ($supplied === null) {
            return $current;
        }

        $addendum = sprintf('[ADDENDUM — %s] %s', $label, $supplied);

        return $current === null || trim($current) === ''
            ? $addendum
            : $current."\n\n".$addendum;
    }
}
