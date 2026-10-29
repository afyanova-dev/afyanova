<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use App\Platform\Documents\Contracts\DTO\DocumentDto;
use App\Platform\Documents\Contracts\DTO\DocumentVersionDto;
use App\Platform\Documents\Domain\Enum\DocumentKind;
use App\Platform\Documents\Domain\Enum\DocumentStatus;
use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Model\Document;
use App\Platform\Documents\Domain\Model\DocumentVersion;
use App\Platform\Documents\Domain\Port\DocumentStoragePort;
use App\Platform\Documents\Domain\Port\DocumentTemplateRendererPort;
use App\Platform\Documents\Domain\Port\PdfRendererPort;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentTemplateRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentVersionRepositoryInterface;
use App\Platform\Documents\Domain\ValueObject\StorageKey;

final class GenerateDocumentHandler
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentVersionRepositoryInterface $versionRepository,
        private readonly DocumentTemplateRepositoryInterface $templateRepository,
        private readonly DocumentTemplateRendererPort $renderer,
        private readonly DocumentStoragePort $storagePort,
        private readonly ?PdfRendererPort $pdfRenderer = null,
    ) {}

    public function __invoke(GenerateDocumentCommand $command): DocumentDto
    {
        $template = $this->templateRepository->findByIdAndVersion($command->templateId, $command->templateVersion);
        if ($template === null) {
            throw new DocumentNotFoundException("Document template '{$command->templateId}' version '{$command->templateVersion}' not found.");
        }

        // F1 & F3: Documents is the sole authoritative calculator of generation_context_hash
        $normalizedPayload = $command->payload;
        $this->ksortRecursive($normalizedPayload);
        $canonicalJson = (string) json_encode($normalizedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $generationContextHash = hash('sha256', $canonicalJson.':'.$template->layout);

        // Render document output
        $renderedBytes = $this->renderer->render($template, $command->payload);

        $extension = 'html';
        $mimeType = 'text/html';

        if ($command->format === 'pdf') {
            $pdfRenderer = $this->pdfRenderer ?? app(PdfRendererPort::class);
            $renderedBytes = $pdfRenderer->renderHtmlToPdf($renderedBytes);
            $extension = 'pdf';
            $mimeType = 'application/pdf';
        }

        $contentHash = hash('sha256', $renderedBytes);
        $byteSize = strlen($renderedBytes);

        // Create Document aggregate
        $document = Document::createDocument(
            tenantId: $command->tenantId,
            facilityId: $command->facilityId,
            kind: DocumentKind::GENERATED,
            docType: $command->docType,
            confidentialityLevel: $command->confidentialityLevel,
            sourceDomain: $command->sourceDomain,
            sourceEntityRef: $command->sourceEntityRef,
            encounterId: $command->encounterId,
            patientId: $command->patientId,
            status: DocumentStatus::AVAILABLE,
        );
        $this->documentRepository->save($document);

        // F7: Canonical, platform-generated storage key
        $storageKey = StorageKey::forVersion(
            tenantId: $command->tenantId,
            documentId: $document->id,
            versionNo: 1,
            contentHash: $contentHash,
            extension: $extension,
        );

        $this->storagePort->put($storageKey->toString(), $renderedBytes);

        // Create Version 1
        $version = DocumentVersion::createVersion(
            tenantId: $command->tenantId,
            documentId: $document->id,
            versionNo: 1,
            mimeType: $mimeType,
            byteSize: $byteSize,
            contentHash: $contentHash,
            createdBy: $command->actorId,
            status: DocumentStatus::AVAILABLE,
            storageKey: $storageKey->toString(),
            templateId: $template->id,
            templateVersion: $template->version,
            generationPayload: $command->payload,
            generationContextHash: $generationContextHash,
        );
        $this->versionRepository->save($version);

        // Update document current version pointer
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
            templateId: $version->template_id,
            templateVersion: $version->template_version,
            generationPayload: $version->generation_payload,
            generationContextHash: $version->generation_context_hash,
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

    /**
     * @param  array<string, mixed>  $array
     */
    private function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
