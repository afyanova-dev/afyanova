<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Dto;

use App\Platform\Integration\Domain\Enum\InboundMessageStatus;

final readonly class InboundWebhookResultDto
{
    public function __construct(
        public string $messageId,
        public InboundMessageStatus $status,
        public bool $acknowledged,
        public ?string $domainOutcome = null,
        public ?string $reason = null,
    ) {}
}
