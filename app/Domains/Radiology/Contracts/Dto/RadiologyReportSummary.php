<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Dto;

/**
 * Summary read of one radiology report (ADR-167 D-F). A protected read model,
 * not an event payload — the ADR-136 deny-list applies to events / audit, not
 * to a gated query result for an authorized caller, so the prose sections are
 * present here.
 */
final readonly class RadiologyReportSummary
{
    public function __construct(
        public string $reportId,
        public string $facilityId,
        public string $studyId,
        public string $orderId,
        public string $orderItemId,
        public string $patientId,
        public string $encounterId,
        public string $accessionNumber,
        public string $modalityCode,
        public string $serviceCode,
        public ?string $clinicalIndicationText,
        public ?string $techniqueText,
        public ?string $findingsText,
        public ?string $impressionText,
        public ?string $recommendationsText,
        public string $status,
        public ?string $releaseHoldReason,
        public int $amendmentCount,
        public ?string $cancellationReason,
        public bool $criticalFindingFlagged,
        public string $authoredByUserId,
        public ?string $authoredAt,
        public ?string $reviewedByUserId,
        public ?string $reviewedAt,
        public ?string $verifiedByUserId,
        public ?string $verifiedAt,
        public ?string $releasedByUserId,
        public ?string $releasedAt,
    ) {}
}
