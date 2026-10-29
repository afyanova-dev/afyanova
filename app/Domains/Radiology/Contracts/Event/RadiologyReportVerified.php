<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * A radiology report was verified (§42, §177; ADR-167 D-G).
 *
 * PHI-safe: identifiers only (ADR-136 deny-list).
 */
final readonly class RadiologyReportVerified
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
