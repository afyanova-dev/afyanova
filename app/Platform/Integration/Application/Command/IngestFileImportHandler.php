<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Application\Service\FileImportReconciliationService;
use App\Platform\Integration\Domain\Model\FileImport;

final readonly class IngestFileImportHandler
{
    public function __construct(
        private FileImportReconciliationService $service,
    ) {}

    public function __invoke(IngestFileImportCommand $command): FileImport
    {
        return $this->service->ingest($command->dto);
    }
}
