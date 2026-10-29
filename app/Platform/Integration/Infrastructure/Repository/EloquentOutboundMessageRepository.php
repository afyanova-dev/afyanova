<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Repository;

use App\Platform\Integration\Domain\Enum\OutboundMessageStatus;
use App\Platform\Integration\Domain\Model\OutboundMessage;
use App\Platform\Integration\Domain\Repository\OutboundMessageRepository;
use Carbon\CarbonImmutable;

final class EloquentOutboundMessageRepository implements OutboundMessageRepository
{
    public function findById(string $id): ?OutboundMessage
    {
        return OutboundMessage::query()->where('id', $id)->first();
    }

    /** @return list<OutboundMessage> */
    public function findPending(int $limit = 50): array
    {
        $now = CarbonImmutable::now();

        return array_values(OutboundMessage::query()
            ->where(function ($query) use ($now) {
                $query->where('status', OutboundMessageStatus::QUEUED->value)
                    ->orWhere(function ($retryQuery) use ($now) {
                        $retryQuery->where('status', OutboundMessageStatus::FAILED->value)
                            ->where('next_retry_at', '<=', $now);
                    });
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->all());
    }

    /** @return list<OutboundMessage> */
    public function findDeadLetters(int $limit = 50): array
    {
        return array_values(OutboundMessage::query()
            ->where('status', OutboundMessageStatus::DEAD_LETTER->value)
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->all());
    }

    public function save(OutboundMessage $message): void
    {
        $message->save();
    }
}
