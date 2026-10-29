<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Dto;

/**
 * Summary read of one radiology critical finding (ADR-169 D-F). A protected
 * read model, not an event payload.
 */
final readonly class RadiologyCriticalFindingSummary
{
    public function __construct(
        public string $criticalFindingId,
        public string $facilityId,
        public string $reportId,
        public string $studyId,
        public string $orderId,
        public string $patientId,
        public string $accessionNumber,
        public string $modalityCode,
        public string $findingSummary,
        public string $severity,
        public string $status,
        public string $identifiedByUserId,
        public ?string $identifiedAt,
        public ?string $acknowledgedByUserId,
        public ?string $acknowledgedAt,
        public ?string $acknowledgementNote,
    ) {}
}
