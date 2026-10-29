<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A laboratory result was identified as critical (14-laboratory-domain.md
 * §54, §138, §142; ADR-161 D-H). Notifications consumes this to alert the
 * ordering clinician / responsible team (§55 — PHASE 12).
 *
 * PHI-safe: identifiers + the coded `severity` only (ADR-136 deny-list).
 */
final readonly class CriticalLabResultIdentified
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $criticalResultId,
        public string $resultId,
        public string $orderId,
        public string $patientId,
        public string $testCode,
        public string $parameterCode,
        public string $severity,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
