<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts;

use App\Platform\Documents\Contracts\DTO\AttachSignatureDto;
use App\Platform\Documents\Contracts\DTO\DocumentDto;
use App\Platform\Documents\Contracts\DTO\DocumentVersionDto;
use App\Platform\Documents\Contracts\DTO\DownloadUrlDto;
use App\Platform\Documents\Contracts\DTO\GenerateDocumentDto;
use App\Platform\Documents\Contracts\DTO\GrantAccessDto;
use App\Platform\Documents\Contracts\DTO\ImposeRetentionDto;
use App\Platform\Documents\Contracts\DTO\ReproducibilityReportDto;
use App\Platform\Documents\Contracts\DTO\RetentionStatusDto;
use App\Platform\Documents\Contracts\DTO\SignatureVerificationDto;
use App\Platform\Documents\Contracts\DTO\UploadDocumentDto;

interface DocumentsApi
{
    public function upload(UploadDocumentDto $dto): DocumentDto;

    public function generate(GenerateDocumentDto $dto): DocumentDto;

    public function attachSignature(AttachSignatureDto $dto): DocumentVersionDto;

    public function grantAccess(GrantAccessDto $dto): void;

    public function revokeAccess(string $grantId): void;

    public function imposeRetention(ImposeRetentionDto $dto): void;

    public function releaseRetention(string $requirementId): void;

    public function getDocument(string $documentId): ?DocumentDto;

    public function getDownloadUrl(string $documentId, ?int $versionNo = null, int $ttlSeconds = 300): DownloadUrlDto;

    public function verifyReproducibility(string $versionId): ReproducibilityReportDto;

    public function verifySignature(string $signatureId): SignatureVerificationDto;

    public function getRetentionStatus(string $documentId): RetentionStatusDto;

    public function redactVersion(string $versionId, string $redactedContents, string $reason, string $actorId): DocumentVersionDto;

    public function disposeVersion(string $versionId, string $reason): void;
}
