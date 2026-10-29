<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

final readonly class RunReportCommand
{
    /**
     * @param  array<string, mixed>|null  $parameters
     */
    public function __construct(
        public string $tenantId,
        public string $reportDefinitionCode,
        public ?array $parameters = null,
        public ?string $requestedBy = null,
        public ?string $facilityId = null,
    ) {}
}
