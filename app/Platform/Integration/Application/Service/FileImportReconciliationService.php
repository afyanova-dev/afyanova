<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Service;

use App\Platform\Integration\Contracts\Dto\FileImportIngestDto;
use App\Platform\Integration\Contracts\Dto\FileImportReconciliationDto;
use App\Platform\Integration\Domain\Enum\DispatchStatus;
use App\Platform\Integration\Domain\Enum\FileImportStatus;
use App\Platform\Integration\Domain\Enum\ImportDomainOutcome;
use App\Platform\Integration\Domain\Exception\ConnectorDisabledException;
use App\Platform\Integration\Domain\Exception\ConnectorNotFoundException;
use App\Platform\Integration\Domain\Model\FileImport;
use App\Platform\Integration\Domain\Model\ImportRecordStatus;
use App\Platform\Integration\Domain\Repository\ConnectorRepository;
use App\Platform\Integration\Domain\Repository\FileImportRepository;

final readonly class FileImportReconciliationService
{
    public function __construct(
        private ConnectorRepository $connectorRepository,
        private FileImportRepository $fileImportRepository,
    ) {}

    public function ingest(FileImportIngestDto $dto): FileImport
    {
        $connector = $this->connectorRepository->findById($dto->connectorId);
        if ($connector === null) {
            throw new ConnectorNotFoundException("Connector '{$dto->connectorId}' not found");
        }

        if (! $connector->status->isOperable()) {
            throw new ConnectorDisabledException("Connector '{$connector->name}' is {$connector->status->value}");
        }

        $recordCount = count($dto->records);

        $fileImport = new FileImport([
            'tenant_id' => $dto->tenantId,
            'connector_id' => $dto->connectorId,
            'external_ref' => $dto->externalRef,
            'filename' => $dto->filename,
            'byte_size' => $dto->byteSize,
            'record_count' => $recordCount,
            'processed_count' => 0,
            'committed_count' => 0,
            'rejected_count' => 0,
            'status' => $recordCount > 0 ? FileImportStatus::RECEIVED : FileImportStatus::RECONCILED,
        ]);

        $this->fileImportRepository->save($fileImport);

        foreach ($dto->records as $index => $record) {
            $recordRef = isset($record['ref']) && is_string($record['ref']) ? $record['ref'] : "REC-{$index}";
            $recordStatus = new ImportRecordStatus([
                'tenant_id' => $dto->tenantId,
                'file_import_id' => $fileImport->id,
                'record_index' => $index,
                'record_ref' => $recordRef,
                'dispatch_status' => DispatchStatus::PENDING,
                'domain_outcome' => ImportDomainOutcome::COMMITTED, // will be settled
            ]);
            $this->fileImportRepository->saveRecordStatus($recordStatus);
        }

        return $fileImport;
    }

    public function recordRecordOutcome(
        string $fileImportId,
        int $recordIndex,
        bool $committed,
        ?string $reason = null,
    ): FileImport {
        $fileImport = $this->fileImportRepository->findById($fileImportId);
        if ($fileImport === null) {
            throw new ConnectorNotFoundException("File import '{$fileImportId}' not found");
        }

        $recordStatus = $this->fileImportRepository->findRecordStatus($fileImportId, $recordIndex);
        if ($recordStatus !== null) {
            if ($committed) {
                $recordStatus->markCommitted($reason);
            } else {
                $recordStatus->markRejected($reason ?? 'Domain rejected import record');
            }
            $this->fileImportRepository->saveRecordStatus($recordStatus);
        }

        $fileImport->incrementProcessed($committed);
        $this->fileImportRepository->save($fileImport);

        return $fileImport;
    }

    public function getReconciliation(string $fileImportId): FileImportReconciliationDto
    {
        $fileImport = $this->fileImportRepository->findById($fileImportId);
        if ($fileImport === null) {
            throw new ConnectorNotFoundException("File import '{$fileImportId}' not found");
        }

        return new FileImportReconciliationDto(
            fileImportId: $fileImport->id,
            status: $fileImport->status,
            recordCount: $fileImport->record_count,
            processedCount: $fileImport->processed_count,
            committedCount: $fileImport->committed_count,
            rejectedCount: $fileImport->rejected_count,
            failureReason: $fileImport->failure_reason,
        );
    }
}
