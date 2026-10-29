<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A critical laboratory result was acknowledged (14-laboratory-domain.md §56,
 * §138; ADR-161 D-H). The acknowledgement note is row-only, not carried here
 * (ADR-136 deny-list).
 */
final readonly class CriticalLabResultAcknowledged
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $criticalResultId,
        public string $resultId,
        public string $orderId,
        public string $patientId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
