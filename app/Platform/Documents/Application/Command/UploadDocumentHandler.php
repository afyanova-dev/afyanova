<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use App\Platform\Documents\Contracts\DTO\DocumentDto;
use App\Platform\Documents\Contracts\DTO\DocumentVersionDto;
use App\Platform\Documents\Domain\Enum\DocumentKind;
use App\Platform\Documents\Domain\Enum\DocumentStatus;
use App\Platform\Documents\Domain\Model\Document;
use App\Platform\Documents\Domain\Model\DocumentVersion;
use App\Platform\Documents\Domain\Port\AntivirusScannerPort;
use App\Platform\Documents\Domain\Port\DocumentStoragePort;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentVersionRepositoryInterface;
use App\Platform\Documents\Domain\ValueObject\StorageKey;

final class UploadDocumentHandler
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentVersionRepositoryInterface $versionRepository,
        private readonly DocumentStoragePort $storagePort,
        private readonly AntivirusScannerPort $antivirusScanner,
    ) {}

    public function __invoke(UploadDocumentCommand $command): DocumentDto
    {
        $contentHash = hash('sha256', $command->fileContents);
        $byteSize = strlen($command->fileContents);

        // F6: Fail-closed ingest — initial status is PENDING_SCAN
        $document = Document::createDocument(
            tenantId: $command->tenantId,
            facilityId: $command->facilityId,
            kind: DocumentKind::UPLOAD,
            docType: $command->docType,
            confidentialityLevel: $command->confidentialityLevel,
            sourceDomain: $command->sourceDomain,
            sourceEntityRef: $command->sourceEntityRef,
            encounterId: $command->encounterId,
            patientId: $command->patientId,
            status: DocumentStatus::PENDING_SCAN,
        );
        $this->documentRepository->save($document);

        // F7: Canonical, platform-generated storage key
        $pathInfo = pathinfo($command->fileName);
        $extension = $pathInfo['extension'] ?? 'bin';

        $storageKey = StorageKey::forVersion(
            tenantId: $command->tenantId,
            documentId: $document->id,
            versionNo: 1,
            contentHash: $contentHash,
            extension: $extension,
        );

        // Store physical bytes
        $this->storagePort->put($storageKey->toString(), $command->fileContents);

        // Antivirus scan
        $scanResult = $this->antivirusScanner->scan($command->fileContents, $command->mimeType);
        $initialStatus = $scanResult->isClean ? DocumentStatus::AVAILABLE : DocumentStatus::QUARANTINED;

        // Create Version 1
        $version = DocumentVersion::createVersion(
            tenantId: $command->tenantId,
            documentId: $document->id,
            versionNo: 1,
            mimeType: $command->mimeType,
            byteSize: $byteSize,
            contentHash: $contentHash,
            createdBy: $command->actorId,
            status: $initialStatus,
            storageKey: $storageKey->toString(),
        );
        $this->versionRepository->save($version);

        // Update document pointer and status
        $document->setCurrentVersion($version);
        $this->documentRepository->save($document);

        $versionDto = new DocumentVersionDto(
            id: $version->id,
            tenantId: $version->tenant_id,
            documentId: $version->document_id,
            versionNo: $version->version_no,
            storageKey: $version->storage_key,
            mimeType: $version->mime_type,
            byteSize: $version->byte_size,
            contentHash: $version->content_hash,
            status: $version->status,
            createdBy: $version->created_by,
            createdAt: $version->created_at,
            updatedAt: $version->updated_at,
        );

        return new DocumentDto(
            id: $document->id,
            tenantId: $document->tenant_id,
            facilityId: $document->facility_id,
            kind: $document->kind,
            docType: $document->doc_type,
            confidentialityLevel: $document->confidentiality_level,
            sourceDomain: $document->source_domain,
            sourceEntityRef: $document->source_entity_ref,
            status: $document->status,
            createdAt: $document->created_at,
            updatedAt: $document->updated_at,
            encounterId: $document->encounter_id,
            patientId: $document->patient_id,
            currentVersionId: $document->current_version_id,
            supersededBy: $document->superseded_by,
            versions: [$versionDto],
        );
    }
}
