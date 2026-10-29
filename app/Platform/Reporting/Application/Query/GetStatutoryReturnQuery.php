<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

final readonly class GetStatutoryReturnQuery
{
    public function __construct(
        public string $tenantId,
        public string $returnId,
    ) {}
}
