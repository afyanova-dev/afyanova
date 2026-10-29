<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

use App\Platform\Reporting\Domain\ValueObject\StatutoryFramework;

final readonly class BuildStatutoryReturnCommand
{
    /**
     * @param  list<array{data_element_code: string, category_option_combo?: string, value: float|int, comment?: string}>  $lines
     */
    public function __construct(
        public string $tenantId,
        public StatutoryFramework $framework,
        public string $periodType,
        public string $periodKey,
        public string $reportingUnitCode,
        public ?string $facilityId = null,
        public ?string $generatedBy = null,
        public array $lines = [],
        public string $referenceConfigVersion = 'v1.0',
    ) {}
}
