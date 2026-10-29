<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Query;

final readonly class GetDownloadStreamQuery
{
    public function __construct(
        public string $tenantId,
        public string $documentId,
        public ?int $versionNo = null,
        public ?string $actorId = null,
        public ?string $capabilityToken = null,
    ) {}
}
