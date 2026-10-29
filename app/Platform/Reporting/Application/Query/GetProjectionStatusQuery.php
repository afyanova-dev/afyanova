<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

final readonly class GetProjectionStatusQuery
{
    public function __construct(
        public string $tenantId,
        public string $streamName,
    ) {}
}
