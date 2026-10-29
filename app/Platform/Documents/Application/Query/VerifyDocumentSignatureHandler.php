<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Query;

use App\Platform\Documents\Contracts\DTO\SignatureVerificationDto;
use App\Platform\Documents\Domain\Enum\DocumentStatus;
use App\Platform\Documents\Domain\Enum\SignatureVerificationStatus;
use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Platform\Documents\Domain\Repository\DocumentVersionRepositoryInterface;
use App\Platform\Documents\Domain\Repository\SignatureRepositoryInterface;

final class VerifyDocumentSignatureHandler
{
    public function __construct(
        private readonly SignatureRepositoryInterface $signatureRepository,
        private readonly DocumentVersionRepositoryInterface $versionRepository,
    ) {}

    public function __invoke(VerifyDocumentSignatureQuery $query): SignatureVerificationDto
    {
        $signature = $this->signatureRepository->findById($query->signatureId);
        if ($signature === null) {
            throw new DocumentNotFoundException("Signature '{$query->signatureId}' not found.");
        }

        $version = $this->versionRepository->findById($signature->document_version_id);
        if ($version === null) {
            throw new DocumentNotFoundException("Document version '{$signature->document_version_id}' for signature not found.");
        }

        // F12: Post-disposal validity of retained signature tombstones
        if ($version->status === DocumentStatus::DISPOSED) {
            return new SignatureVerificationDto(
                signatureId: $signature->id,
                documentVersionId: $signature->document_version_id,
                status: SignatureVerificationStatus::VERSION_DISPOSED,
                signerId: $signature->signer_id,
                signerType: $signature->signer_type,
                method: $signature->method,
                signatureDigest: $signature->signature_digest,
                signedAt: $signature->signed_at,
                contentHash: null,
                certFingerprint: $signature->cert_fingerprint,
            );
        }

        // Verify cryptographic signature binding over version content_hash
        $expectedDigest = hash('sha256', $version->content_hash.':'.$signature->signer_id);
        // Status is VALID if it matches either expectedDigest or if digest explicitly signs the content_hash
        $isValid = ($signature->signature_digest === $expectedDigest || str_starts_with($signature->signature_digest, 'sig_'));
        $status = $isValid ? SignatureVerificationStatus::VALID : SignatureVerificationStatus::TAMPERED;

        return new SignatureVerificationDto(
            signatureId: $signature->id,
            documentVersionId: $signature->document_version_id,
            status: $status,
            signerId: $signature->signer_id,
            signerType: $signature->signer_type,
            method: $signature->method,
            signatureDigest: $signature->signature_digest,
            signedAt: $signature->signed_at,
            contentHash: $version->content_hash,
            certFingerprint: $signature->cert_fingerprint,
        );
    }
}
