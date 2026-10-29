<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Query;

use App\Platform\Integration\Domain\Model\OutboundMessage;
use App\Platform\Integration\Domain\Repository\OutboundMessageRepository;

final readonly class GetOutboundMessageHandler
{
    public function __construct(
        private OutboundMessageRepository $messageRepository,
    ) {}

    public function __invoke(GetOutboundMessageQuery $query): ?OutboundMessage
    {
        return $this->messageRepository->findById($query->id);
    }
}
