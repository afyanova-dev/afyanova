<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Repository;

use App\Platform\Integration\Domain\Model\InboundMessage;
use App\Platform\Integration\Domain\Repository\InboundMessageRepository;

final class EloquentInboundMessageRepository implements InboundMessageRepository
{
    public function findById(string $id): ?InboundMessage
    {
        return InboundMessage::query()->where('id', $id)->first();
    }

    public function findByExternalRef(string $connectorId, string $externalRef): ?InboundMessage
    {
        return InboundMessage::query()
            ->where('connector_id', $connectorId)
            ->where('external_ref', $externalRef)
            ->first();
    }

    /** @return list<InboundMessage> */
    public function search(?string $connectorId = null, ?string $status = null, int $limit = 50): array
    {
        $query = InboundMessage::query()->latest('received_at');

        if ($connectorId !== null) {
            $query->where('connector_id', $connectorId);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        return array_values($query->limit($limit)->get()->all());
    }

    public function save(InboundMessage $message): void
    {
        $message->save();
    }
}
