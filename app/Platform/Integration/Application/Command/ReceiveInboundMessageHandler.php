<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Application\Pipeline\InboundAirlockPipeline;
use App\Platform\Integration\Contracts\Dto\InboundWebhookResultDto;

final readonly class ReceiveInboundMessageHandler
{
    public function __construct(
        private InboundAirlockPipeline $pipeline,
    ) {}

    public function __invoke(ReceiveInboundMessageCommand $command): InboundWebhookResultDto
    {
        return $this->pipeline->process($command->request);
    }
}
