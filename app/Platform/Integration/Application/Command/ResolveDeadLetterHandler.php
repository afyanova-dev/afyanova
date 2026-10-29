<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Application\Pipeline\OutboundDeliveryPipeline;
use App\Platform\Integration\Domain\Model\OutboundMessage;

final readonly class ResolveDeadLetterHandler
{
    public function __construct(
        private OutboundDeliveryPipeline $pipeline,
    ) {}

    public function __invoke(ResolveDeadLetterCommand $command): OutboundMessage
    {
        return $this->pipeline->resolveDeadLetter($command->messageId, $command->resolutionReason);
    }
}
