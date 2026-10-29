<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Application\Service\FileImportReconciliationService;
use App\Platform\Integration\Domain\Model\FileImport;

final readonly class RecordImportRecordOutcomeHandler
{
    public function __construct(
        private FileImportReconciliationService $service,
    ) {}

    public function __invoke(RecordImportRecordOutcomeCommand $command): FileImport
    {
        return $this->service->recordRecordOutcome(
            fileImportId: $command->fileImportId,
            recordIndex: $command->recordIndex,
            committed: $command->committed,
            reason: $command->reason,
        );
    }
}
