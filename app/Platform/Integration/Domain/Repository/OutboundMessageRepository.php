<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Repository;

use App\Platform\Integration\Domain\Model\OutboundMessage;

interface OutboundMessageRepository
{
    public function findById(string $id): ?OutboundMessage;

    /** @return list<OutboundMessage> */
    public function findPending(int $limit = 50): array;

    /** @return list<OutboundMessage> */
    public function findDeadLetters(int $limit = 50): array;

    public function save(OutboundMessage $message): void;
}
