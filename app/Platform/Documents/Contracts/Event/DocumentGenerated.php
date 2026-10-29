<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\Event;

use Carbon\CarbonImmutable;

final readonly class DocumentGenerated
{
    public function __construct(
        public string $tenantId,
        public string $facilityId,
        public string $documentId,
        public string $versionId,
        public string $docType,
        public string $templateId,
        public string $templateVersion,
        public string $generationContextHash,
        public CarbonImmutable $generatedAt,
    ) {}
}
