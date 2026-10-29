<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

/**
 * Minimal summary read of one laboratory result (ADR-159 D-G). A protected read
 * model for an authorized caller — the value (including free-text findings) is
 * present here; the ADR-136 deny-list applies to events / audit, not to a gated
 * query result.
 */
final readonly class LabResultSummary
{
    public function __construct(
        public string $resultId,
        public string $orderId,
        public string $orderItemId,
        public string $specimenId,
        public string $patientId,
        public string $facilityId,
        public string $testCode,
        public string $parameterCode,
        public string $parameterName,
        public string $valueType,
        public ?string $valueNumeric,
        public ?string $valueCode,
        public ?string $valueText,
        public ?string $unit,
        public ?string $referenceLow,
        public ?string $referenceHigh,
        public ?string $referenceText,
        public ?string $referenceRangeSource,
        public ?string $abnormalFlag,
        public string $resultSource,
        public ?string $method,
        public string $status,
        public ?string $releaseHoldReason,
        public ?string $enteredAt,
        public ?string $verifiedAt,
        public ?string $releasedAt,
    ) {}
}
