<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Repository;

use App\Platform\Integration\Domain\Model\FileImport;
use App\Platform\Integration\Domain\Model\ImportRecordStatus;

interface FileImportRepository
{
    public function findById(string $id): ?FileImport;

    public function save(FileImport $fileImport): void;

    public function findRecordStatus(string $fileImportId, int $recordIndex): ?ImportRecordStatus;

    public function saveRecordStatus(ImportRecordStatus $status): void;
}
