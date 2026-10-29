<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Query;

use App\Platform\Integration\Application\Service\FileImportReconciliationService;
use App\Platform\Integration\Contracts\Dto\FileImportReconciliationDto;

final readonly class GetFileImportReconciliationHandler
{
    public function __construct(
        private FileImportReconciliationService $service,
    ) {}

    public function __invoke(GetFileImportReconciliationQuery $query): FileImportReconciliationDto
    {
        return $this->service->getReconciliation($query->fileImportId);
    }
}
