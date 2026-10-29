<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * A critical imaging finding was acknowledged (15-radiology-domain.md §100,
 * §167, §177; ADR-169 D-G).
 *
 * PHI-safe: identifiers only (ADR-136 deny-list — the acknowledgement note is
 * row-only, never here).
 */
final readonly class CriticalImagingFindingAcknowledged
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $criticalFindingId,
        public string $reportId,
        public string $studyId,
        public string $orderId,
        public string $patientId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
