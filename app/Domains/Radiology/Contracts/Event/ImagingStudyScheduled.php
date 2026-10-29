<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * An imaging study was scheduled (§104, §177; ADR-166 D-H).
 *
 * PHI-safe: identifiers and coded values only (ADR-136 deny-list).
 */
final readonly class ImagingStudyScheduled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $imagingStudyId,
        public string $orderId,
        public string $orderItemId,
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
