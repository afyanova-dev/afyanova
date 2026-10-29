<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

/**
 * Summary read of one critical-result record (ADR-161 D-G). A protected read
 * model for an authorized caller.
 */
final readonly class LabCriticalResultSummary
{
    public function __construct(
        public string $criticalResultId,
        public string $resultId,
        public string $orderId,
        public string $patientId,
        public string $facilityId,
        public string $testCode,
        public string $parameterCode,
        public string $severity,
        public ?string $clinicalContext,
        public string $status,
        public string $identifiedByUserId,
        public ?string $identifiedAt,
        public ?string $acknowledgedByUserId,
        public ?string $acknowledgedAt,
        public ?string $acknowledgementNote,
    ) {}
}
