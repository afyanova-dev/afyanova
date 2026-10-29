<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

use App\Platform\Reporting\Domain\ValueObject\ReportCategory;

final readonly class DefineReportDefinitionCommand
{
    /**
     * @param  array<string, mixed>  $queryTemplate
     * @param  list<string>|null  $distributionList
     */
    public function __construct(
        public string $tenantId,
        public string $code,
        public string $name,
        public ReportCategory $category,
        public string $dataMartCode,
        public array $queryTemplate,
        public ?string $description = null,
        public ?string $scheduleCron = null,
        public ?array $distributionList = null,
    ) {}
}
