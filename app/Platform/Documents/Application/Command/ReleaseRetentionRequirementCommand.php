<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

final readonly class ReleaseRetentionRequirementCommand
{
    public function __construct(
        public string $tenantId,
        public string $requirementId,
        public string $actorId,
    ) {}
}
