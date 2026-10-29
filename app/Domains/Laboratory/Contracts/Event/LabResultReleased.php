<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A laboratory result was released to authorized clinical users
 * (14-laboratory-domain.md §138, §143; ADR-159 D-H). PHI-safe: identifiers +
 * the coded `abnormalFlag` only. Notifications / Clinical acknowledgement
 * consume this in their own phase.
 */
final readonly class LabResultReleased
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $resultId,
        public string $orderId,
        public string $patientId,
        public string $testCode,
        public string $parameterCode,
        public ?string $abnormalFlag,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
