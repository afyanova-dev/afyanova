<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * A radiology report was corrected / addended (§44, §177; ADR-168 D-F). Old / new text never carried.
 *
 * PHI-safe: identifiers only (ADR-136 deny-list).
 */
final readonly class RadiologyReportAmended
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
