<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * A radiology report was created as DRAFT (15-radiology-domain.md §159, §177;
 * ADR-167 D-G). PHI-safe: identifiers and coded values only (ADR-136 deny-list
 * — the report prose is NEVER carried).
 */
final readonly class RadiologyReportCreated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $reportId,
        public string $studyId,
        public string $orderId,
        public string $patientId,
        public string $encounterId,
        public string $accessionNumber,
        public string $modalityCode,
        public string $serviceCode,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
