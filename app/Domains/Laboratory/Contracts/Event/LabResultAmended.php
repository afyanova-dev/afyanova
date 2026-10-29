<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A released/verified laboratory result was corrected (14-laboratory-domain.md
 * §61, §138; ADR-160 D-H). The old and new values + the reason live on the
 * append-only `lab_result_amendments` row — NEVER in this event (ADR-136
 * deny-list). Only identifiers + the (new) coded `abnormalFlag`.
 */
final readonly class LabResultAmended
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
