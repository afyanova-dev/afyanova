<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

final readonly class LabExecutionMaterialRequirementSummary
{
    /**
     * @param  list<LabExecutionMaterialUseSummary>  $uses
     */
    public function __construct(
        public string $requirementId,
        public string $inventoryItemId,
        public string $itemCode,
        public string $itemName,
        public string $itemUom,
        public string $role,
        public bool $required,
        public string $usageScope,
        public string $requiredQuantity,
        public string $consumedQuantity,
        public string $remainingQuantity,
        public array $uses,
    ) {}
}
