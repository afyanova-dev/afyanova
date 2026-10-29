<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use App\Platform\Documents\Contracts\DTO\DocumentVersionDto;
use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Model\DocumentSignature;
use App\Platform\Documents\Domain\Repository\DocumentVersionRepositoryInterface;
use App\Platform\Documents\Domain\Repository\SignatureRepositoryInterface;

final class AttachSignatureHandler
{
    public function __construct(
        private readonly DocumentVersionRepositoryInterface $versionRepository,
        private readonly SignatureRepositoryInterface $signatureRepository,
    ) {}

    public function __invoke(AttachSignatureCommand $command): DocumentVersionDto
    {
        $version = $this->versionRepository->findById($command->documentVersionId);
        if ($version === null) {
            throw new DocumentNotFoundException("Document version '{$command->documentVersionId}' not found.");
        }

        // F13: Assert version can be accessed and is not in pending disposal or disposed
        $version->assertAccessible();

        $signature = DocumentSignature::createSignature(
            tenantId: $command->tenantId,
            documentVersionId: $version->id,
            signerId: $command->signerId,
            signerType: $command->signerType,
            method: $command->method,
            signatureDigest: $command->signatureDigest,
            signedAt: $command->signedAt,
            authEventRef: $command->authEventRef,
            certFingerprint: $command->certFingerprint,
        );
        $this->signatureRepository->save($signature);

        return new DocumentVersionDto(
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
            derivedFromVersionId: $version->derived_from_version_id,
            redactionReason: $version->redaction_reason,
            disposedAt: $version->disposed_at,
        );
    }
}
