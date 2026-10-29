<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Query;

use App\Platform\Documents\Contracts\DTO\DownloadUrlDto;
use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\TokenAction;
use App\Platform\Documents\Domain\Exception\DocumentNotAvailableException;
use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Repository\AccessGrantRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentVersionRepositoryInterface;
use App\Platform\Documents\Domain\ValueObject\DocumentCapabilityToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class GetDownloadUrlHandler
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRepository,
        private readonly DocumentVersionRepositoryInterface $versionRepository,
        private readonly AccessGrantRepositoryInterface $accessGrantRepository,
    ) {}

    public function __invoke(GetDownloadUrlQuery $query): DownloadUrlDto
    {
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

        $version = $query->versionNo !== null
            ? $this->versionRepository->findByDocumentAndVersion($document->id, $query->versionNo)
            : $this->versionRepository->getLatestVersion($document->id);

        if ($version === null) {
            throw new DocumentNotFoundException("Document version for document '{$query->documentId}' not found.");
        }

        $version->assertAccessible();

        $expiresAt = CarbonImmutable::now()->addSeconds($query->ttlSeconds);
        $jti = (string) Str::uuid();

        $capabilityToken = new DocumentCapabilityToken(
            jti: $jti,
            tenantId: $query->tenantId,
            documentId: $document->id,
            versionNo: $version->version_no,
            action: TokenAction::DOWNLOAD,
            expiresAt: $expiresAt,
        );

        $appKey = (string) config('app.key');
        $encodedToken = $capabilityToken->sign($appKey);
        $downloadUrl = "/api/v1/documents/{$document->id}/download?token={$encodedToken}";

        return new DownloadUrlDto(
            documentId: $document->id,
            versionNo: $version->version_no,
            token: $encodedToken,
            expiresAt: $expiresAt,
            url: $downloadUrl,
        );
    }
}
