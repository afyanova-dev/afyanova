<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

final readonly class ReviewStatutoryReturnCommand
{
    public function __construct(
        public string $tenantId,
        public string $returnId,
        public string $reviewedBy,
    ) {}
}
