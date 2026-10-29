<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A laboratory result was invalidated (14-laboratory-domain.md §63, §138;
 * ADR-160 D-H). The reason is row-only + on the audit row, never here
 * (ADR-136 deny-list).
 */
final readonly class LabResultCancelled
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
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
