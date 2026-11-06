<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

final readonly class LabExecutionSummary
{
    /**
     * @param  list<LabExecutionMaterialRequirementSummary>  $materials
     */
    public function __construct(
        public string $executionId,
        public string $orderId,
        public string $orderItemId,
        public string $specimenId,
        public string $orderNumber,
        public string $testCode,
        public string $testName,
        public int $attemptNumber,
        public string $methodId,
        public string $methodCode,
        public string $methodName,
        public int $methodVersion,
        public ?string $instrumentId,
        public ?string $instrumentIdentifier,
        public string $status,
        public string $startedAt,
        public ?string $completedAt,
        public ?string $abortedAt,
        public ?string $abortReason,
        public array $materials,
    ) {}
}
