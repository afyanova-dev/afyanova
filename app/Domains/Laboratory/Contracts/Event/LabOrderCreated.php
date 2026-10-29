<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A laboratory order was created as DRAFT (14-laboratory-domain.md §138–§139;
 * ADR-157 D-H).
 *
 * PHI-safe: identifiers and coded values only (ADR-136 deny-list —
 * clinical_indication_text / rejection_reason / cancellation_reason never
 * carried).
 */
final readonly class LabOrderCreated
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
