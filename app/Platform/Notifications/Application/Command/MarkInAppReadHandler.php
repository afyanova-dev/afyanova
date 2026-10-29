<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Command;

use App\Platform\Notifications\Domain\Exception\NotificationNotFoundException;
use App\Platform\Notifications\Domain\Repository\InAppNotificationRepositoryInterface;

final readonly class MarkInAppReadHandler
{
    public function __construct(private InAppNotificationRepositoryInterface $inAppRepository) {}

    public function __invoke(MarkInAppReadCommand $command): void
    {
        $notification = $this->inAppRepository->findById($command->notificationId);
        if ($notification === null || $notification->user_id !== $command->userId) {
            throw NotificationNotFoundException::forId($command->notificationId);
        }

        $notification->markAsRead();
        $this->inAppRepository->save($notification);
    }
}
