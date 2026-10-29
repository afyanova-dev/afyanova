<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

final readonly class QueryDataMartQuery
{
    /**
     * @param  array<string, mixed>|null  $filters
     */
    public function __construct(
        public string $tenantId,
        public string $dataMartCode,
        public ?array $filters = null,
        public int $limit = 100,
    ) {}
}
