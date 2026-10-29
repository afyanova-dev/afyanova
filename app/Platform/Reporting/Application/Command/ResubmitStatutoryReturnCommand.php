<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

final readonly class ResubmitStatutoryReturnCommand
{
    /**
     * @param  list<array{data_element_code: string, category_option_combo?: string, value: float|int, comment?: string}>  $lines
     */
    public function __construct(
        public string $tenantId,
        public string $originalReturnId,
        public array $lines,
        public ?string $submittedBy = null,
    ) {}
}
