<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use App\Platform\Documents\Domain\Enum\SignatureMethod;
use App\Platform\Documents\Domain\Enum\SignerType;
use Carbon\CarbonImmutable;

final readonly class AttachSignatureCommand
{
    public function __construct(
        public string $tenantId,
        public string $documentVersionId,
        public string $signerId,
        public SignerType $signerType,
        public SignatureMethod $method,
        public string $signatureDigest,
        public CarbonImmutable $signedAt,
        public ?string $authEventRef = null,
        public ?string $certFingerprint = null,
    ) {}
}
