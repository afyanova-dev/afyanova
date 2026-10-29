<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Service;

use App\Platform\Documents\Application\Command\AttachSignatureCommand;
use App\Platform\Documents\Application\Command\DisposeDocumentVersionCommand;
use App\Platform\Documents\Application\Command\GenerateDocumentCommand;
use App\Platform\Documents\Application\Command\GrantDocumentAccessCommand;
use App\Platform\Documents\Application\Command\ImposeRetentionRequirementCommand;
use App\Platform\Documents\Application\Command\RedactDocumentCommand;
use App\Platform\Documents\Application\Command\ReleaseRetentionRequirementCommand;
use App\Platform\Documents\Application\Command\RevokeDocumentAccessCommand;
use App\Platform\Documents\Application\Command\UploadDocumentCommand;
use App\Platform\Documents\Application\Query\GetDocumentQuery;
use App\Platform\Documents\Application\Query\GetDownloadUrlQuery;
use App\Platform\Documents\Application\Query\GetRetentionStatusQuery;
use App\Platform\Documents\Application\Query\VerifyDocumentReproducibilityQuery;
use App\Platform\Documents\Application\Query\VerifyDocumentSignatureQuery;
use App\Platform\Documents\Contracts\DocumentsApi;
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
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;

final readonly class DocumentsApiService implements DocumentsApi
{
    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    private function tenantId(): string
    {
        return app(TenantContext::class)->tenantId();
    }

    public function upload(UploadDocumentDto $dto): DocumentDto
    {
        /** @var DocumentDto */
        return $this->commandBus->dispatch(new UploadDocumentCommand(
            tenantId: $dto->tenantId,
            facilityId: $dto->facilityId,
            docType: $dto->docType,
            confidentialityLevel: $dto->confidentialityLevel,
            sourceDomain: $dto->sourceDomain,
            sourceEntityRef: $dto->sourceEntityRef,
            fileContents: $dto->fileContents,
            mimeType: $dto->mimeType,
            fileName: $dto->fileName,
            actorId: $dto->actorId,
            encounterId: $dto->encounterId,
            patientId: $dto->patientId,
        ));
    }

    public function generate(GenerateDocumentDto $dto): DocumentDto
    {
        /** @var DocumentDto */
        return $this->commandBus->dispatch(new GenerateDocumentCommand(
            tenantId: $dto->tenantId,
            facilityId: $dto->facilityId,
            docType: $dto->docType,
            confidentialityLevel: $dto->confidentialityLevel,
            sourceDomain: $dto->sourceDomain,
            sourceEntityRef: $dto->sourceEntityRef,
            templateId: $dto->templateId,
            templateVersion: $dto->templateVersion,
            payload: $dto->payload,
            actorId: $dto->actorId,
            encounterId: $dto->encounterId,
            patientId: $dto->patientId,
            format: $dto->format ?? 'html',
        ));
    }

    public function attachSignature(AttachSignatureDto $dto): DocumentVersionDto
    {
        /** @var DocumentVersionDto */
        return $this->commandBus->dispatch(new AttachSignatureCommand(
            tenantId: $dto->tenantId,
            documentVersionId: $dto->documentVersionId,
            signerId: $dto->signerId,
            signerType: $dto->signerType,
            method: $dto->method,
            signatureDigest: $dto->signatureDigest,
            signedAt: $dto->signedAt,
            authEventRef: $dto->authEventRef,
            certFingerprint: $dto->certFingerprint,
        ));
    }

    public function grantAccess(GrantAccessDto $dto): void
    {
        $this->commandBus->dispatch(new GrantDocumentAccessCommand(
            tenantId: $dto->tenantId,
            documentId: $dto->documentId,
            principalId: $dto->principalId,
            scope: $dto->scope,
            expiresAt: $dto->expiresAt,
            grantedBy: $dto->grantedBy,
        ));
    }

    public function revokeAccess(string $grantId): void
    {
        $tenantId = $this->tenantId();
        $this->commandBus->dispatch(new RevokeDocumentAccessCommand(
            tenantId: $tenantId,
            grantId: $grantId,
        ));
    }

    public function imposeRetention(ImposeRetentionDto $dto): void
    {
        $this->commandBus->dispatch(new ImposeRetentionRequirementCommand(
            tenantId: $dto->tenantId,
            documentId: $dto->documentId,
            imposedBy: $dto->imposedBy,
            reason: $dto->reason,
            actorId: $dto->actorId,
            retainUntil: $dto->retainUntil,
            indefinite: $dto->indefinite,
        ));
    }

    public function releaseRetention(string $requirementId): void
    {
        $tenantId = $this->tenantId();
        $this->commandBus->dispatch(new ReleaseRetentionRequirementCommand(
            tenantId: $tenantId,
            requirementId: $requirementId,
            actorId: 'system',
        ));
    }

    public function getDocument(string $documentId): ?DocumentDto
    {
        $tenantId = $this->tenantId();

        /** @var DocumentDto|null */
        return $this->queryBus->dispatch(new GetDocumentQuery(
            tenantId: $tenantId,
            documentId: $documentId,
        ));
    }

    public function getDownloadUrl(string $documentId, ?int $versionNo = null, int $ttlSeconds = 300): DownloadUrlDto
    {
        $tenantId = $this->tenantId();

        /** @var DownloadUrlDto */
        return $this->queryBus->dispatch(new GetDownloadUrlQuery(
            tenantId: $tenantId,
            documentId: $documentId,
            versionNo: $versionNo,
            ttlSeconds: $ttlSeconds,
        ));
    }

    public function verifyReproducibility(string $versionId): ReproducibilityReportDto
    {
        $tenantId = $this->tenantId();

        /** @var ReproducibilityReportDto */
        return $this->queryBus->dispatch(new VerifyDocumentReproducibilityQuery(
            tenantId: $tenantId,
            versionId: $versionId,
        ));
    }

    public function verifySignature(string $signatureId): SignatureVerificationDto
    {
        $tenantId = $this->tenantId();

        /** @var SignatureVerificationDto */
        return $this->queryBus->dispatch(new VerifyDocumentSignatureQuery(
            tenantId: $tenantId,
            signatureId: $signatureId,
        ));
    }

    public function getRetentionStatus(string $documentId): RetentionStatusDto
    {
        $tenantId = $this->tenantId();

        /** @var RetentionStatusDto */
        return $this->queryBus->dispatch(new GetRetentionStatusQuery(
            tenantId: $tenantId,
            documentId: $documentId,
        ));
    }

    public function redactVersion(string $versionId, string $redactedContents, string $reason, string $actorId): DocumentVersionDto
    {
        $tenantId = $this->tenantId();

        /** @var DocumentVersionDto */
        return $this->commandBus->dispatch(new RedactDocumentCommand(
            tenantId: $tenantId,
            versionId: $versionId,
            redactedContents: $redactedContents,
            reason: $reason,
            actorId: $actorId,
        ));
    }

    public function disposeVersion(string $versionId, string $reason): void
    {
        $tenantId = $this->tenantId();
        $this->commandBus->dispatch(new DisposeDocumentVersionCommand(
            tenantId: $tenantId,
            versionId: $versionId,
            reason: $reason,
            actorId: 'system',
        ));
    }
}
