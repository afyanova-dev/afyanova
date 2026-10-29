<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Contracts\Dto\InboundWebhookRequestDto;

final readonly class ReceiveInboundMessageCommand
{
    public function __construct(
        public InboundWebhookRequestDto $request,
    ) {}
}
