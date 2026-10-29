<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\ValueObject\StatutoryFramework;

final readonly class ListStatutoryReturnsQuery
{
    public function __construct(
        public string $tenantId,
        public ?StatutoryFramework $framework = null,
        public ?string $periodKey = null,
    ) {}
}
