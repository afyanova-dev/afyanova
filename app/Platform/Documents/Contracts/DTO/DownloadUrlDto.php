<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class DownloadUrlDto
{
    public function __construct(
        public string $documentId,
        public int $versionNo,
        public string $token,
        public CarbonImmutable $expiresAt,
        public string $url,
    ) {}
}
