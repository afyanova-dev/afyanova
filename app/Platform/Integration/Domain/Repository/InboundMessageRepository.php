<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Repository;

use App\Platform\Integration\Domain\Model\InboundMessage;

interface InboundMessageRepository
{
    public function findById(string $id): ?InboundMessage;

    public function findByExternalRef(string $connectorId, string $externalRef): ?InboundMessage;

    /** @return list<InboundMessage> */
    public function search(?string $connectorId = null, ?string $status = null, int $limit = 50): array;

    public function save(InboundMessage $message): void;
}
