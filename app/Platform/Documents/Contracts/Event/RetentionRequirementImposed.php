<?php

declare(strict_types=1);

namespace App\Platform\Documents\Contracts\Event;

use Carbon\CarbonImmutable;

final readonly class RetentionRequirementImposed
{
    public function __construct(
        public string $tenantId,
        public string $documentId,
        public string $requirementId,
        public string $imposedBy,
        public CarbonImmutable $imposedAt,
    ) {}
}
