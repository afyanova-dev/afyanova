<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Contracts\Event;

use Carbon\CarbonImmutable;

final readonly class StatutoryReturnSuperseded
{
    public function __construct(
        public string $tenantId,
        public string $oldReturnId,
        public string $newReturnId,
        public CarbonImmutable $supersededAt,
    ) {}
}
