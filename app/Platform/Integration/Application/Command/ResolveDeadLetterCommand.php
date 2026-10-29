<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

final readonly class ResolveDeadLetterCommand
{
    public function __construct(
        public string $messageId,
        public string $resolutionReason,
    ) {}
}
