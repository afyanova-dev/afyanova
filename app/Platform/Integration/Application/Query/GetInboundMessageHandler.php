<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Query;

use App\Platform\Integration\Domain\Model\InboundMessage;
use App\Platform\Integration\Domain\Repository\InboundMessageRepository;

final readonly class GetInboundMessageHandler
{
    public function __construct(
        private InboundMessageRepository $messageRepository,
    ) {}

    public function __invoke(GetInboundMessageQuery $query): ?InboundMessage
    {
        return $this->messageRepository->findById($query->id);
    }
}
