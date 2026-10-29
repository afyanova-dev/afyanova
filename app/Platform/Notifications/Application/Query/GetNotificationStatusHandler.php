<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Query;

use App\Platform\Notifications\Contracts\DTO\DeliveryAttemptDto;
use App\Platform\Notifications\Contracts\DTO\NotificationRequestDto;
use App\Platform\Notifications\Domain\Model\DeliveryAttempt;
use App\Platform\Notifications\Domain\Model\NotificationRequest;
use App\Platform\Notifications\Domain\Repository\NotificationRequestRepositoryInterface;

final readonly class GetNotificationStatusHandler
{
    public function __construct(private NotificationRequestRepositoryInterface $requestRepository) {}

    public function __invoke(GetNotificationStatusQuery $query): ?NotificationRequestDto
    {
        $request = $this->requestRepository->findById($query->requestId);
        if ($request === null) {
            return null;
        }

        return $this->toDto($request);
    }

    private function toDto(NotificationRequest $request): NotificationRequestDto
    {
        $attempts = $request->attempts->map(fn (DeliveryAttempt $a): DeliveryAttemptDto => new DeliveryAttemptDto(
            id: (string) $a->id,
            notificationRequestId: (string) $a->notification_request_id,
            channel: $a->channel->value,
            provider: $a->provider,
            providerMessageId: $a->provider_message_id,
            maskedDestination: $a->masked_destination,
            status: $a->status->value,
            errorCode: $a->error_code,
            errorMessage: $a->error_message,
            attemptedAt: $a->attempted_at,
            deliveredAt: $a->delivered_at,
        ))->all();

        return new NotificationRequestDto(
            id: (string) $request->id,
            tenantId: (string) $request->tenant_id,
            templateKey: $request->template_key,
            templateVersion: $request->template_version,
            recipientType: $request->recipient_type->value,
            recipientId: (string) $request->recipient_id,
            onBehalfOfDomain: $request->on_behalf_of_domain,
            onBehalfOfEntity: $request->on_behalf_of_entity,
            priority: $request->priority->value,
            status: $request->status->value,
            suppressionReason: $request->suppression_reason?->value,
            attempts: array_values($attempts),
            createdAt: $request->created_at,
            updatedAt: $request->updated_at,
        );
    }
}
