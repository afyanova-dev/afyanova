<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Contracts;

use App\Platform\Notifications\Contracts\DTO\BulkNotificationResponseDto;
use App\Platform\Notifications\Contracts\DTO\ChannelPreferenceDto;
use App\Platform\Notifications\Contracts\DTO\InAppNotificationDto;
use App\Platform\Notifications\Contracts\DTO\NotificationRequestDto;
use App\Platform\Notifications\Contracts\DTO\NotificationTemplateDto;
use App\Platform\Notifications\Contracts\DTO\SendBulkNotificationDto;
use App\Platform\Notifications\Contracts\DTO\SendNotificationDto;
use App\Platform\Notifications\Contracts\DTO\SetChannelPreferenceDto;

/**
 * Public contract port for Notifications platform service (27-notifications.md; AGENTS §5.2).
 *
 * Provides outward messaging capabilities with strict tenant isolation, zero PII retention,
 * and asynchronous delivery.
 */
interface NotificationsApi
{
    public function send(SendNotificationDto $dto): NotificationRequestDto;

    public function sendBulk(SendBulkNotificationDto $dto): BulkNotificationResponseDto;

    public function cancelPending(string $requestId): void;

    public function setChannelPreference(SetChannelPreferenceDto $dto): void;

    /**
     * @return list<InAppNotificationDto>
     */
    public function getInAppNotifications(string $userId, int $limit = 50): array;

    public function markInAppRead(string $notificationId, string $userId): void;

    public function getStatus(string $requestId): ?NotificationRequestDto;

    public function getChannelPreference(string $subjectType, string $subjectId): ?ChannelPreferenceDto;

    public function getTemplate(string $key, ?int $version = null): ?NotificationTemplateDto;
}
