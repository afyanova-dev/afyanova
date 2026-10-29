<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Query;

final readonly class GetDocumentQuery
{
    public function __construct(
        public string $tenantId,
        public string $documentId,
        public ?string $actorId = null,
        public ?string $capabilityToken = null,
    ) {}
}
