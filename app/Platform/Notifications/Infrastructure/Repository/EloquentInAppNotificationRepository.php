<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Repository;

use App\Platform\Notifications\Domain\Model\InAppNotification;
use App\Platform\Notifications\Domain\Repository\InAppNotificationRepositoryInterface;

final class EloquentInAppNotificationRepository implements InAppNotificationRepositoryInterface
{
    public function findById(string $id): ?InAppNotification
    {
        return InAppNotification::query()->whereKey($id)->first();
    }

    /**
     * @return list<InAppNotification>
     */
    public function listForUser(string $userId, int $limit = 50): array
    {
        /** @var list<InAppNotification> */
        return InAppNotification::query()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function save(InAppNotification $notification): void
    {
        $notification->save();
    }
}
