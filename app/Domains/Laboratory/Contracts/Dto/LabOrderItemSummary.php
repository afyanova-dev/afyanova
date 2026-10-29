<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

/**
 * One requested-test line on a laboratory order (ADR-157 D-G). A protected read
 * model, not an event payload.
 */
final readonly class LabOrderItemSummary
{
    public function __construct(
        public string $itemId,
        public string $testDefinitionId,
        public string $testCode,
        public string $testName,
        public int $testDefinitionVersion,
        public string $status,
        public ?string $orderedViaPanelId = null,
    ) {}
}
