<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\Event;

use Carbon\CarbonImmutable;

final readonly class DocumentRedacted
{
    public function __construct(
        public string $tenantId,
        public string $documentId,
        public string $originalVersionId,
        public string $redactedVersionId,
        public string $redactedBy,
        public CarbonImmutable $redactedAt,
    ) {}
}
