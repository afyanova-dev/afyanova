<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Contracts\Dto\FileImportIngestDto;

final readonly class IngestFileImportCommand
{
    public function __construct(
        public FileImportIngestDto $dto,
    ) {}
}
