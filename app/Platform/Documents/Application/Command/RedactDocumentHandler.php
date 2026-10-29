<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use App\Platform\Documents\Contracts\DTO\DocumentVersionDto;
use App\Platform\Documents\Domain\Enum\DocumentStatus;
use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Model\DocumentVersion;
use App\Platform\Documents\Domain\Port\DocumentStoragePort;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentVersionRepositoryInterface;
use App\Platform\Documents\Domain\ValueObject\StorageKey;

final class RedactDocumentHandler
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentVersionRepositoryInterface $versionRepository,
        private readonly DocumentStoragePort $storagePort,
    ) {}

    public function __invoke(RedactDocumentCommand $command): DocumentVersionDto
    {
        $originalVersion = $this->versionRepository->findById($command->versionId);
        if ($originalVersion === null) {
            throw new DocumentNotFoundException("Document version '{$command->versionId}' not found.");
        }

        $originalVersion->assertAccessible();

        $document = $this->documentRepository->findById($originalVersion->document_id);
        if ($document === null) {
            throw new DocumentNotFoundException("Document '{$originalVersion->document_id}' not found.");
        }

        $latestVersion = $this->versionRepository->getLatestVersion($document->id);
        $nextVersionNo = $latestVersion !== null ? $latestVersion->version_no + 1 : 1;

        $contentHash = hash('sha256', $command->redactedContents);
        $byteSize = strlen($command->redactedContents);

        $storageKey = StorageKey::forVersion(
            tenantId: $command->tenantId,
            documentId: $document->id,
            versionNo: $nextVersionNo,
            contentHash: $contentHash,
            extension: 'pdf',
        );

        $this->storagePort->put($storageKey->toString(), $command->redactedContents);

        $redactedVersion = DocumentVersion::createVersion(
            tenantId: $command->tenantId,
            documentId: $document->id,
            versionNo: $nextVersionNo,
            mimeType: $originalVersion->mime_type,
            byteSize: $byteSize,
            contentHash: $contentHash,
            createdBy: $command->actorId,
            status: DocumentStatus::AVAILABLE,
            storageKey: $storageKey->toString(),
            derivedFromVersionId: $originalVersion->id,
            redactionReason: $command->reason,
            redactedBy: $command->actorId,
        );
        $this->versionRepository->save($redactedVersion);

        // Update document current version
        $document->setCurrentVersion($redactedVersion);
        $this->documentRepository->save($document);

        return new DocumentVersionDto(
            id: $redactedVersion->id,
            tenantId: $redactedVersion->tenant_id,
            documentId: $redactedVersion->document_id,
            versionNo: $redactedVersion->version_no,
            storageKey: $redactedVersion->storage_key,
            mimeType: $redactedVersion->mime_type,
            byteSize: $redactedVersion->byte_size,
            contentHash: $redactedVersion->content_hash,
            status: $redactedVersion->status,
            createdBy: $redactedVersion->created_by,
            createdAt: $redactedVersion->created_at,
            updatedAt: $redactedVersion->updated_at,
            derivedFromVersionId: $redactedVersion->derived_from_version_id,
            redactionReason: $redactedVersion->redaction_reason,
        );
    }
}
