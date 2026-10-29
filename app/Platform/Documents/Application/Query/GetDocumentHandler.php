<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Query;

use App\Platform\Documents\Contracts\DTO\DocumentDto;
use App\Platform\Documents\Contracts\DTO\DocumentVersionDto;
use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\TokenAction;
use App\Platform\Documents\Domain\Exception\AmbiguousCredentialsException;
use App\Platform\Documents\Domain\Exception\DocumentNotAvailableException;
use App\Platform\Documents\Domain\Exception\InvalidCapabilityTokenException;
use App\Platform\Documents\Domain\Repository\AccessGrantRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Platform\Documents\Domain\ValueObject\DocumentCapabilityToken;

final class GetDocumentHandler
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly AccessGrantRepositoryInterface $accessGrantRepository,
    ) {}

    public function __invoke(GetDocumentQuery $query): ?DocumentDto
    {
        // F11: Mutual exclusivity check — reject ambiguous credentials
        if ($query->actorId !== null && $query->capabilityToken !== null) {
            throw new AmbiguousCredentialsException('Ambiguous credentials: both actor identity and capability token supplied.');
        }

        $document = $this->documentRepository->findByIdWithVersions($query->documentId);
        if ($document === null) {
            return null;
        }

        // Path B: External capability token
        if ($query->capabilityToken !== null) {
            $appKey = (string) config('app.key');
            $token = DocumentCapabilityToken::parseAndVerify($query->capabilityToken, $appKey);

            if ($token->tenantId !== $query->tenantId) {
                throw new InvalidCapabilityTokenException('Token tenant does not match request tenant.');
            }

            if ($token->documentId !== $query->documentId) {
                throw new InvalidCapabilityTokenException('Token document ID does not match requested document.');
            }

            if (! in_array($token->action, [TokenAction::METADATA, TokenAction::DOWNLOAD], true)) {
                throw new InvalidCapabilityTokenException('Token action does not permit metadata access.');
            }
        } elseif ($query->actorId !== null) {
            // Path A: Staff session
            // F2 & F8: If RESTRICTED, Documents mechanically checks for explicit domain grant
            if ($document->confidentiality_level === ConfidentialityLevel::RESTRICTED) {
                $hasGrant = $this->accessGrantRepository->hasActiveGrant($document->id, $query->actorId, 'VIEW');
                if (! $hasGrant) {
                    throw new DocumentNotAvailableException('Access denied: principal lacks active access grant for restricted document.');
                }
            }
        }

        /** @var list<DocumentVersionDto> $versionDtos */
        $versionDtos = array_values($document->versions->map(function ($v): DocumentVersionDto {
            return new DocumentVersionDto(
                id: $v->id,
                tenantId: $v->tenant_id,
                documentId: $v->document_id,
                versionNo: $v->version_no,
                storageKey: $v->storage_key,
                mimeType: $v->mime_type,
                byteSize: $v->byte_size,
                contentHash: $v->content_hash,
                status: $v->status,
                createdBy: $v->created_by,
                createdAt: $v->created_at,
                updatedAt: $v->updated_at,
                templateId: $v->template_id,
                templateVersion: $v->template_version,
                generationPayload: $v->generation_payload,
                generationContextHash: $v->generation_context_hash,
                derivedFromVersionId: $v->derived_from_version_id,
                redactionReason: $v->redaction_reason,
                disposedAt: $v->disposed_at,
            );
        })->all());

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
            versions: $versionDtos,
        );
    }
}
