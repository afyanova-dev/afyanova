<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Query;

final readonly class GetRetentionStatusQuery
{
    public function __construct(
        public string $tenantId,
        public string $documentId,
    ) {}
}
