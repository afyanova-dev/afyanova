<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

/**
 * Summary read of one laboratory panel and its member tests (ADR-162 D-G).
 */
final readonly class LabPanelSummary
{
    /**
     * @param  list<array{testDefinitionId: string, testCode: string, testName: string, sequence: int}>  $members
     */
    public function __construct(
        public string $panelId,
        public string $code,
        public string $name,
        public ?string $category,
        public ?string $description,
        public bool $active,
        public int $version,
        public array $members,
    ) {}
}
