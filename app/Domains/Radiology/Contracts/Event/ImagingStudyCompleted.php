<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * An imaging study completed (§62, §104, §165, §177; ADR-166 D-H). The
 * "a billable imaging examination occurred" signal — the trigger Billing
 * consumes in its own phase (§62 — Radiology does not create the invoice).
 *
 * PHI-safe: identifiers and coded values only (ADR-136 deny-list).
 */
final readonly class ImagingStudyCompleted
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
