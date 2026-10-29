<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\DTO;

use App\Platform\Documents\Domain\Enum\SignatureMethod;
use App\Platform\Documents\Domain\Enum\SignatureVerificationStatus;
use App\Platform\Documents\Domain\Enum\SignerType;
use Carbon\CarbonImmutable;

final readonly class SignatureVerificationDto
{
    public function __construct(
        public string $signatureId,
        public string $documentVersionId,
        public SignatureVerificationStatus $status,
        public string $signerId,
        public SignerType $signerType,
        public SignatureMethod $method,
        public string $signatureDigest,
        public CarbonImmutable $signedAt,
        public ?string $contentHash = null,
        public ?string $certFingerprint = null,
    ) {}
}
