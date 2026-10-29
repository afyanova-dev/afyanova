<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * A DRAFT imaging order was submitted (DRAFT → ORDERED) (15-radiology-domain.md
 * §20, §177; ADR-165 D-I). The "a request now exists to be worked" signal —
 * the trigger Billing / Financial Access consume in their own phase (§62–§63).
 *
 * PHI-safe: identifiers and coded values only (ADR-136 deny-list).
 */
final readonly class ImagingOrderSubmitted
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $orderId,
        public string $patientId,
        public string $encounterId,
        public string $orderNumber,
        public string $priority,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
