<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Repository;

use App\Platform\Notifications\Domain\Model\InAppNotification;

interface InAppNotificationRepositoryInterface
{
    public function findById(string $id): ?InAppNotification;

    /**
     * @return list<InAppNotification>
     */
    public function listForUser(string $userId, int $limit = 50): array;

    public function save(InAppNotification $notification): void;
}
