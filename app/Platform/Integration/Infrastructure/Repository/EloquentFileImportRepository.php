<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Repository;

use App\Platform\Integration\Domain\Model\FileImport;
use App\Platform\Integration\Domain\Model\ImportRecordStatus;
use App\Platform\Integration\Domain\Repository\FileImportRepository;

final class EloquentFileImportRepository implements FileImportRepository
{
    public function findById(string $id): ?FileImport
    {
        return FileImport::query()->where('id', $id)->first();
    }

    public function save(FileImport $fileImport): void
    {
        $fileImport->save();
    }

    public function findRecordStatus(string $fileImportId, int $recordIndex): ?ImportRecordStatus
    {
        return ImportRecordStatus::query()
            ->where('file_import_id', $fileImportId)
            ->where('record_index', $recordIndex)
            ->first();
    }

    public function saveRecordStatus(ImportRecordStatus $status): void
    {
        $status->save();
    }
}
