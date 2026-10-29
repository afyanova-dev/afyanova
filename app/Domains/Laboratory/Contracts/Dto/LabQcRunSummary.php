<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

/**
 * Summary read of one QC run (ADR-163 D-G).
 */
final readonly class LabQcRunSummary
{
    public function __construct(
        public string $qcRunId,
        public string $facilityId,
        public string $analyzerRef,
        public ?string $testDefinitionId,
        public ?string $testCode,
        public string $controlMaterial,
        public ?string $controlLevel,
        public ?string $expectedLow,
        public ?string $expectedHigh,
        public string $observedValue,
        public ?string $unit,
        public string $status,
        public ?string $reviewedByUserId,
        public ?string $reviewedAt,
        public ?string $reviewNote,
        public string $performedByUserId,
        public ?string $performedAt,
    ) {}
}
