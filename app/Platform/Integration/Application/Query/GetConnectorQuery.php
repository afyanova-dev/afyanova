<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Query;

final readonly class GetConnectorQuery
{
    public function __construct(
        public string $id,
    ) {}
}
