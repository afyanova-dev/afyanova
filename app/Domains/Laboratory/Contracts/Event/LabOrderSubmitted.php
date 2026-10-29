<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A DRAFT laboratory order was submitted (DRAFT → ORDERED)
 * (14-laboratory-domain.md §24, §138; ADR-157 D-H). The "a request now exists
 * to be worked" signal — the trigger Billing / Financial Access consume in
 * their own phase.
 *
 * PHI-safe: identifiers and coded values only (ADR-136 deny-list).
 */
final readonly class LabOrderSubmitted
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
