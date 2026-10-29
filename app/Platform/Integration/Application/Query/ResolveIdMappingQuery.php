<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Query;

final readonly class ResolveIdMappingQuery
{
    public function __construct(
        public string $connectorId,
        public ?string $externalId = null,
        public ?string $internalDomain = null,
        public ?string $internalId = null,
    ) {}
}
