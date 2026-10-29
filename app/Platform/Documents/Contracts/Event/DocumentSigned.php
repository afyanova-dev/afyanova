<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\Event;

use Carbon\CarbonImmutable;

final readonly class DocumentSigned
{
    public function __construct(
        public string $tenantId,
        public string $documentId,
        public string $versionId,
        public string $signatureId,
        public string $signerId,
        public string $signerType,
        public CarbonImmutable $signedAt,
    ) {}
}
