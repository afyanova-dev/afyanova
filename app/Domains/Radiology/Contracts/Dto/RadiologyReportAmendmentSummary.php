<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Dto;

/**
 * One amendment row on a radiology report (ADR-168 D-E). A protected read
 * model, not an event payload.
 */
final readonly class RadiologyReportAmendmentSummary
{
    public function __construct(
        public string $amendmentId,
        public string $reportId,
        public int $sequence,
        public string $amendmentType,
        public string $previousStatus,
        public string $newStatus,
        public ?string $previousFindingsText,
        public ?string $previousImpressionText,
        public ?string $previousRecommendationsText,
        public ?string $newFindingsText,
        public ?string $newImpressionText,
        public ?string $newRecommendationsText,
        public string $reason,
        public string $amendedByUserId,
        public ?string $amendedAt,
    ) {}
}
