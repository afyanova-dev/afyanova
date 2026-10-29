<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Query;

final readonly class VerifyDocumentSignatureQuery
{
    public function __construct(
        public string $tenantId,
        public string $signatureId,
    ) {}
}
