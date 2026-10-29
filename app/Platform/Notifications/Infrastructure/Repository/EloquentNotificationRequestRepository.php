<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Repository;

use App\Platform\Notifications\Domain\Model\NotificationRequest;
use App\Platform\Notifications\Domain\Repository\NotificationRequestRepositoryInterface;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;

final class EloquentNotificationRequestRepository extends EloquentRepository implements NotificationRequestRepositoryInterface
{
    protected function model(): string
    {
        return NotificationRequest::class;
    }

    public function save(NotificationRequest|AggregateRoot $aggregate): void
    {
        parent::save($aggregate);
    }

    public function findById(string $id): ?NotificationRequest
    {
        /** @var NotificationRequest|null */
        return NotificationRequest::query()->with('attempts')->whereKey($id)->first();
    }

    public function findByDedupeKey(string $dedupeKey): ?NotificationRequest
    {
        /** @var NotificationRequest|null */
        return NotificationRequest::query()->with('attempts')->where('dedupe_key', $dedupeKey)->first();
    }
}
