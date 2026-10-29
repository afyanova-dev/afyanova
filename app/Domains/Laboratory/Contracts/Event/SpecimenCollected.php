<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A specimen was collected for a laboratory order (14-laboratory-domain.md
 * §138, §140; ADR-158 D-H).
 *
 * PHI-safe: identifiers and coded values only (ADR-136 deny-list).
 */
final readonly class SpecimenCollected
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $specimenId,
        public string $orderId,
        public string $patientId,
        public string $accessionNumber,
        public string $specimenType,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
