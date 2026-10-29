<?php

declare(strict_types=1);

namespace App\Platform\Documents\Application\Command;

final readonly class RevokeDocumentAccessCommand
{
    public function __construct(
        public string $tenantId,
        public string $grantId,
    ) {}
}
