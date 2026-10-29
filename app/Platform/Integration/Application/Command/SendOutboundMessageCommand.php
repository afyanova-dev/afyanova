<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Contracts\Dto\OutboundEnqueueDto;

final readonly class SendOutboundMessageCommand
{
    public function __construct(
        public OutboundEnqueueDto $dto,
        public bool $dispatchNow = true,
    ) {}
}
