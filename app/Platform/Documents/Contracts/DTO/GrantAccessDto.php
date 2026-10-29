<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\DTO;

use Carbon\CarbonImmutable;

final readonly class GrantAccessDto
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
