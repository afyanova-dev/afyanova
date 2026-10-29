<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Dto;

use App\Platform\Integration\Domain\Enum\FileImportStatus;

final readonly class FileImportReconciliationDto
{
    public function __construct(
        public string $fileImportId,
        public FileImportStatus $status,
        public int $recordCount,
        public int $processedCount,
        public int $committedCount,
        public int $rejectedCount,
        public ?string $failureReason = null,
    ) {}
}
