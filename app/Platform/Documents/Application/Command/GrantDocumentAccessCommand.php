<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

use Carbon\CarbonImmutable;

final readonly class GrantDocumentAccessCommand
{
    public function __construct(
        public string $tenantId,
        public string $documentId,
        public string $principalId,
        public string $scope,
        public CarbonImmutable $expiresAt,
        public string $grantedBy,
    ) {}
}
