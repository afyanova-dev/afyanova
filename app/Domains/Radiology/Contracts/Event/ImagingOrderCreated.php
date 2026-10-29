<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Event;

/**
 * An imaging order was created as DRAFT (15-radiology-domain.md §177, §159;
 * ADR-165 D-I).
 *
 * PHI-safe: identifiers and coded values only (ADR-136 deny-list —
 * clinical_indication_text / rejection_reason / cancellation_reason never
 * carried).
 */
final readonly class ImagingOrderCreated
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
