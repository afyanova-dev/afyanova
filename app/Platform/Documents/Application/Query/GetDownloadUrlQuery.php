<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Query;

final readonly class GetDownloadUrlQuery
{
    public function __construct(
        public string $tenantId,
        public string $documentId,
        public ?int $versionNo = null,
        public int $ttlSeconds = 300,
        public ?string $actorId = null,
    ) {}
}
