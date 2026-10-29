<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Domain\Enum\MappingSource;
use App\Platform\Integration\Domain\Enum\VerificationStatus;

final readonly class UpsertIdMappingCommand
{
    public function __construct(
        public string $tenantId,
        public string $connectorId,
        public string $externalId,
        public string $internalDomain,
        public string $internalId,
        public MappingSource $source = MappingSource::DOMAIN_PROVIDED,
        public VerificationStatus $status = VerificationStatus::VERIFIED,
        public float $confidence = 1.0,
    ) {}
}
