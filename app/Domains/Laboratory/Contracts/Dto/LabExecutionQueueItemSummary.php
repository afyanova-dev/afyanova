<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

final readonly class LabExecutionQueueItemSummary
{
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public string $priority,
        public string $orderItemId,
        public string $testDefinitionId,
        public string $testCode,
        public string $testName,
        /** @var list<array{specimenId: string, accessionNumber: string, specimenType: string, containerType: string|null}> */
        public array $acceptedSpecimens,
        public ?string $executionId,
    ) {}
}
