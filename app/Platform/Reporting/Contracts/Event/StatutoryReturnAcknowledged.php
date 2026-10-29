<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Contracts\Event;

use Carbon\CarbonImmutable;

final readonly class StatutoryReturnAcknowledged
{
    public function __construct(
        public string $tenantId,
        public string $returnId,
        public string $returnNumber,
        public string $framework,
        public ?string $acknowledgementRef,
        public CarbonImmutable $acknowledgedAt,
    ) {}
}
