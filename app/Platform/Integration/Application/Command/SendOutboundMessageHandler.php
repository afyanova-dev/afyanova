<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Application\Pipeline\OutboundDeliveryPipeline;
use App\Platform\Integration\Domain\Model\OutboundMessage;

final readonly class SendOutboundMessageHandler
{
    public function __construct(
        private OutboundDeliveryPipeline $pipeline,
    ) {}

    public function __invoke(SendOutboundMessageCommand $command): OutboundMessage
    {
        $message = $this->pipeline->enqueue($command->dto);

        if ($command->dispatchNow) {
            $message = $this->pipeline->dispatch($message->id);
        }

        return $message;
    }
}
