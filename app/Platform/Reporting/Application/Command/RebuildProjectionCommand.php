<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

final readonly class RebuildProjectionCommand
{
    public function __construct(
        public string $tenantId,
        public string $streamName,
        public ?string $facilityId = null,
        public string $referenceConfigVersion = 'v1.0',
    ) {}
}
