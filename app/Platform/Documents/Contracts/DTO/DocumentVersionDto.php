<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\DTO;

use App\Platform\Documents\Domain\Enum\DocumentStatus;
use Carbon\CarbonImmutable;

final readonly class DocumentVersionDto
{
    /**
     * @param  array<string, mixed>|null  $generationPayload
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $documentId,
        public int $versionNo,
        public ?string $storageKey,
        public string $mimeType,
        public int $byteSize,
        public string $contentHash,
        public DocumentStatus $status,
        public string $createdBy,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
        public ?string $templateId = null,
        public ?string $templateVersion = null,
        public ?array $generationPayload = null,
        public ?string $generationContextHash = null,
        public ?string $derivedFromVersionId = null,
        public ?string $redactionReason = null,
        public ?CarbonImmutable $disposedAt = null,
    ) {}
}
