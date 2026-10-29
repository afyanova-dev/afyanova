<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Application\Pipeline\OutboundDeliveryPipeline;
use App\Platform\Integration\Domain\Model\OutboundMessage;

final readonly class RetryOutboundMessageHandler
{
    public function __construct(
        private OutboundDeliveryPipeline $pipeline,
    ) {}

    public function __invoke(RetryOutboundMessageCommand $command): OutboundMessage
    {
        return $this->pipeline->retry($command->messageId);
    }
}
