<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Query;

use App\Platform\Notifications\Contracts\DTO\InAppNotificationDto;
use App\Platform\Notifications\Domain\Model\InAppNotification;
use App\Platform\Notifications\Domain\Repository\InAppNotificationRepositoryInterface;
use Carbon\CarbonImmutable;

final readonly class GetInAppNotificationsHandler
{
    public function __construct(private InAppNotificationRepositoryInterface $inAppRepository) {}

    /**
     * @return list<InAppNotificationDto>
     */
    public function __invoke(GetInAppNotificationsQuery $query): array
    {
        $notifications = $this->inAppRepository->listForUser($query->userId, $query->limit);

        return array_map(
            fn (InAppNotification $n): InAppNotificationDto => new InAppNotificationDto(
                id: (string) $n->id,
                userId: (string) $n->user_id,
                notificationRequestId: $n->notification_request_id,
                title: $n->title,
                body: $n->body,
                actionUrl: $n->action_url,
                readAt: $n->read_at,
                createdAt: $n->created_at ?? CarbonImmutable::now(),
            ),
            $notifications
        );
    }
}
