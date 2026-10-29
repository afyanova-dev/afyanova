<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

final readonly class DisposeDocumentVersionCommand
{
    public function __construct(
        public string $tenantId,
        public string $versionId,
        public string $reason,
        public string $actorId,
    ) {}
}
