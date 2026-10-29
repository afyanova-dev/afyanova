<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\DTO;

final readonly class DownloadStreamDto
{
    public function __construct(
        public string $documentId,
        public int $versionNo,
        public string $contents,
        public string $mimeType,
        public int $byteSize,
        public string $contentHash,
    ) {}
}
