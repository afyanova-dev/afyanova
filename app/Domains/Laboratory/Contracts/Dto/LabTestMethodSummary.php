<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

/**
 * Current tenant catalog view of a test method and its current material set.
 */
final readonly class LabTestMethodSummary
{
    /**
     * @param  list<array{
     *     requirementId: string,
     *     inventoryItemId: string,
     *     itemCode: string,
     *     itemName: string,
     *     itemUom: string,
     *     itemType: string,
     *     itemCategory: string|null,
     *     role: string,
     *     required: bool,
     *     usageScope: string,
     *     quantity: string
     * }>  $requirements
     */
    public function __construct(
        public string $methodId,
        public string $testDefinitionId,
        public string $code,
        public string $name,
        public ?string $description,
        public ?string $specimenType,
        public ?string $containerType,
        public bool $active,
        public int $version,
        public array $requirements,
    ) {}
}
