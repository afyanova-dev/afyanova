<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * A non-released radiology report was cancelled (§164, §177; ADR-168 D-F). Reason row-only + audit, never here.
 *
 * PHI-safe: identifiers only (ADR-136 deny-list).
 */
final readonly class RadiologyReportCancelled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $reportId,
        public string $studyId,
        public string $orderId,
        public string $patientId,
        public string $accessionNumber,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
