<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A laboratory result was verified (14-laboratory-domain.md §138, §141;
 * ADR-159 D-H). PHI-safe: identifiers + the coded `abnormalFlag` only.
 */
final readonly class LabResultVerified
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
