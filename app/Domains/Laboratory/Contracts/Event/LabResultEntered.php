<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A laboratory result was entered (14-laboratory-domain.md §138; ADR-159 D-H).
 *
 * PHI-safe: identifiers + the coded `abnormalFlag` only. The value
 * (value_numeric / value_code / value_text) is NEVER carried (ADR-136
 * deny-list) — a consumer queries the gated read model for it.
 */
final readonly class LabResultEntered
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $resultId,
        public string $orderId,
        public string $specimenId,
        public string $patientId,
        public string $testCode,
        public string $parameterCode,
        public ?string $abnormalFlag,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
