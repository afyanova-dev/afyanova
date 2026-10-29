<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Service;

use App\Platform\Notifications\Application\Command\CancelPendingNotificationCommand;
use App\Platform\Notifications\Application\Command\MarkInAppReadCommand;
use App\Platform\Notifications\Application\Command\SendBulkNotificationCommand;
use App\Platform\Notifications\Application\Command\SendNotificationCommand;
use App\Platform\Notifications\Application\Command\SetChannelPreferenceCommand;
use App\Platform\Notifications\Application\Query\GetChannelPreferenceQuery;
use App\Platform\Notifications\Application\Query\GetInAppNotificationsQuery;
use App\Platform\Notifications\Application\Query\GetNotificationStatusQuery;
use App\Platform\Notifications\Application\Query\GetNotificationTemplateQuery;
use App\Platform\Notifications\Contracts\DTO\BulkNotificationResponseDto;
use App\Platform\Notifications\Contracts\DTO\ChannelPreferenceDto;
use App\Platform\Notifications\Contracts\DTO\InAppNotificationDto;
use App\Platform\Notifications\Contracts\DTO\NotificationRequestDto;
use App\Platform\Notifications\Contracts\DTO\NotificationTemplateDto;
use App\Platform\Notifications\Contracts\DTO\SendBulkNotificationDto;
use App\Platform\Notifications\Contracts\DTO\SendNotificationDto;
use App\Platform\Notifications\Contracts\DTO\SetChannelPreferenceDto;
use App\Platform\Notifications\Contracts\NotificationsApi;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;

final readonly class NotificationsApiService implements NotificationsApi
{
    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    public function send(SendNotificationDto $dto): NotificationRequestDto
    {
        /** @var NotificationRequestDto */
        return $this->commandBus->dispatch(new SendNotificationCommand(
            templateKey: $dto->templateKey,
            recipientType: $dto->recipientType,
            recipientId: $dto->recipientId,
            onBehalfOfDomain: $dto->onBehalfOfDomain,
            onBehalfOfEntity: $dto->onBehalfOfEntity,
            context: $dto->context,
            channels: $dto->channels,
            priority: $dto->priority,
            dedupeKey: $dto->dedupeKey,
            consentBasisRef: $dto->consentBasisRef,
        ));
    }

    public function sendBulk(SendBulkNotificationDto $dto): BulkNotificationResponseDto
    {
        /** @var BulkNotificationResponseDto */
        return $this->commandBus->dispatch(new SendBulkNotificationCommand(
            templateKey: $dto->templateKey,
            recipients: $dto->recipients,
            onBehalfOfDomain: $dto->onBehalfOfDomain,
            onBehalfOfEntity: $dto->onBehalfOfEntity,
            context: $dto->context,
            channels: $dto->channels,
            priority: $dto->priority,
            consentBasisRef: $dto->consentBasisRef,
        ));
    }

    public function cancelPending(string $requestId): void
    {
        $this->commandBus->dispatch(new CancelPendingNotificationCommand($requestId));
    }

    public function setChannelPreference(SetChannelPreferenceDto $dto): void
    {
        $this->commandBus->dispatch(new SetChannelPreferenceCommand(
            subjectType: $dto->subjectType,
            subjectId: $dto->subjectId,
            preferredChannel: $dto->preferredChannel,
            mutedCategories: $dto->mutedCategories,
            quietHoursStart: $dto->quietHoursStart,
            quietHoursEnd: $dto->quietHoursEnd,
            preferredLanguage: $dto->preferredLanguage,
        ));
    }

    /**
     * @return list<InAppNotificationDto>
     */
    public function getInAppNotifications(string $userId, int $limit = 50): array
    {
        /** @var list<InAppNotificationDto> */
        return $this->queryBus->dispatch(new GetInAppNotificationsQuery($userId, $limit));
    }

    public function markInAppRead(string $notificationId, string $userId): void
    {
        $this->commandBus->dispatch(new MarkInAppReadCommand($notificationId, $userId));
    }

    public function getStatus(string $requestId): ?NotificationRequestDto
    {
        /** @var NotificationRequestDto|null */
        return $this->queryBus->dispatch(new GetNotificationStatusQuery($requestId));
    }

    public function getChannelPreference(string $subjectType, string $subjectId): ?ChannelPreferenceDto
    {
        /** @var ChannelPreferenceDto|null */
        return $this->queryBus->dispatch(new GetChannelPreferenceQuery($subjectType, $subjectId));
    }

    public function getTemplate(string $key, ?int $version = null): ?NotificationTemplateDto
    {
        /** @var NotificationTemplateDto|null */
        return $this->queryBus->dispatch(new GetNotificationTemplateQuery($key, $version));
    }
}
