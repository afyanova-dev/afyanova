<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * A critical imaging finding was identified on a radiology report
 * (15-radiology-domain.md §46, §101, §177; ADR-169 D-G). Notifications
 * delivers (§55 / §101 — PHASE 12); Clinical acknowledges (§100 / §167).
 *
 * PHI-safe: identifiers + the coded `severity` only (ADR-136 deny-list — the
 * free-text `finding_summary` is row-only, never here).
 */
final readonly class CriticalImagingFindingIdentified
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
        public string $accessionNumber,
        public string $severity,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
