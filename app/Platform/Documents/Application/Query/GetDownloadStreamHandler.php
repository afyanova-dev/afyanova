<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Query;

use App\Platform\Documents\Contracts\DTO\DownloadStreamDto;
use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\TokenAction;
use App\Platform\Documents\Domain\Exception\AmbiguousCredentialsException;
use App\Platform\Documents\Domain\Exception\DocumentNotAvailableException;
use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Exception\InvalidCapabilityTokenException;
use App\Platform\Documents\Domain\Exception\TokenAlreadyConsumedException;
use App\Platform\Documents\Domain\Port\DocumentStoragePort;
use App\Platform\Documents\Domain\Repository\AccessGrantRepositoryInterface;
use App\Platform\Documents\Domain\Repository\ConsumedTokenRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentVersionRepositoryInterface;
use App\Platform\Documents\Domain\ValueObject\DocumentCapabilityToken;
use Carbon\CarbonImmutable;

final class GetDownloadStreamHandler
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentVersionRepositoryInterface $versionRepository,
        private readonly AccessGrantRepositoryInterface $accessGrantRepository,
        private readonly ConsumedTokenRepositoryInterface $consumedTokenRepository,
        private readonly DocumentStoragePort $storagePort,
    ) {}

    public function __invoke(GetDownloadStreamQuery $query): DownloadStreamDto
    {
        // F11: Mutual exclusivity — reject ambiguous credentials
        if ($query->actorId !== null && $query->capabilityToken !== null) {
            throw new AmbiguousCredentialsException('Ambiguous credentials: both actor credentials and capability token supplied.');
        }

        $versionNo = $query->versionNo;

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

            if ($token->action !== TokenAction::DOWNLOAD) {
                throw new InvalidCapabilityTokenException('Token action is not authorized for download.');
            }

            $versionNo = $token->versionNo;

            // F11: Atomic single-use replay protection
            $now = CarbonImmutable::now();
            $recorded = $this->consumedTokenRepository->recordConsumption(
                tenantId: $token->tenantId,
                jti: $token->jti,
                consumedAt: $now,
                expiresAt: $token->expiresAt,
            );

            if (! $recorded) {
                throw new TokenAlreadyConsumedException("Capability token '{$token->jti}' has already been consumed.");
            }
        } elseif ($query->actorId === null) {
            // Neither provided
            throw new DocumentNotAvailableException('Access denied: no credentials or capability token provided.');
        }

        $document = $this->documentRepository->findById($query->documentId);
        if ($document === null) {
            throw new DocumentNotFoundException("Document '{$query->documentId}' not found.");
        }

        if ($query->actorId !== null && $document->confidentiality_level === ConfidentialityLevel::RESTRICTED) {
            $hasGrant = $this->accessGrantRepository->hasActiveGrant($document->id, $query->actorId, 'DOWNLOAD');
            if (! $hasGrant) {
                throw new DocumentNotAvailableException('Access denied: principal lacks active DOWNLOAD grant for restricted document.');
            }
        }

        $version = $versionNo !== null
            ? $this->versionRepository->findByDocumentAndVersion($document->id, $versionNo)
            : $this->versionRepository->getLatestVersion($document->id);

        if ($version === null) {
            throw new DocumentNotFoundException("Document version for document '{$query->documentId}' not found.");
        }

        // F6 & F13: Fail-closed accessibility assertion (blocks PENDING_DISPOSAL, DISPOSED, QUARANTINED, etc.)
        $version->assertAccessible();
        if (! $version->canBeAccessed()) {
            throw new DocumentNotAvailableException("Document version '{$version->id}' status '{$version->status->value}' cannot be downloaded.");
        }

        if ($version->storage_key === null) {
            throw new DocumentNotFoundException("Storage key not present for version '{$version->id}'.");
        }

        $contents = $this->storagePort->get($version->storage_key);

        return new DownloadStreamDto(
            documentId: $document->id,
            versionNo: $version->version_no,
            contents: $contents,
            mimeType: $version->mime_type,
            byteSize: $version->byte_size,
            contentHash: $version->content_hash,
        );
    }
}
