<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Command;

use App\Platform\Notifications\Contracts\DTO\DeliveryAttemptDto;
use App\Platform\Notifications\Contracts\DTO\NotificationRequestDto;
use App\Platform\Notifications\Domain\Exception\DuplicateNotificationException;
use App\Platform\Notifications\Domain\Exception\NotificationTemplateNotFoundException;
use App\Platform\Notifications\Domain\Model\DeliveryAttempt;
use App\Platform\Notifications\Domain\Model\NotificationPriority;
use App\Platform\Notifications\Domain\Model\NotificationRequest;
use App\Platform\Notifications\Domain\Model\RecipientType;
use App\Platform\Notifications\Domain\Repository\DeduplicationRepositoryInterface;
use App\Platform\Notifications\Domain\Repository\NotificationRequestRepositoryInterface;
use App\Platform\Notifications\Domain\Repository\NotificationTemplateRepositoryInterface;
use App\Platform\Notifications\Infrastructure\Job\DispatchNotificationJob;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;

final readonly class SendNotificationHandler
{
    public function __construct(
        private Container $container,
        private NotificationRequestRepositoryInterface $requestRepository,
        private NotificationTemplateRepositoryInterface $templateRepository,
        private DeduplicationRepositoryInterface $deduplicationRepository,
    ) {}

    public function __invoke(SendNotificationCommand $command): NotificationRequestDto
    {
        /** @var TenantContext $tenantContext */
        $tenantContext = $this->container->make(TenantContext::class);
        $tenantId = $tenantContext->tenantId();

        $template = $this->templateRepository->findByKey($command->templateKey, $command->templateVersion);
        if ($template === null || ! $template->is_active) {
            throw NotificationTemplateNotFoundException::forKey($command->templateKey, $command->templateVersion);
        }

        $requestId = (string) Str::uuid();

        // F6: Atomic deduplication check
        if ($command->dedupeKey !== null && $command->dedupeKey !== '') {
            $locked = $this->deduplicationRepository->acquireLock($command->dedupeKey, $requestId);
            if (! $locked) {
                throw DuplicateNotificationException::forKey($command->dedupeKey);
            }
        }

        $recipientType = RecipientType::from($command->recipientType);
        $priority = NotificationPriority::tryFrom($command->priority) ?? NotificationPriority::ROUTINE;

        $request = NotificationRequest::create(
            tenantId: $tenantId,
            templateKey: $template->key,
            templateVersion: $template->version,
            recipientType: $recipientType,
            recipientId: $command->recipientId,
            onBehalfOfDomain: $command->onBehalfOfDomain,
            onBehalfOfEntity: $command->onBehalfOfEntity,
            priority: $priority,
            contextPayload: $command->context,
            consentBasisRef: $command->consentBasisRef,
            dedupeKey: $command->dedupeKey,
        );
        $request->id = $requestId;

        $this->requestRepository->save($request);

        // F5: Asynchronous dispatch job
        DispatchNotificationJob::dispatch(
            requestId: $request->id,
            requestedChannels: $command->channels,
            rawContext: $command->context,
            tenantId: $tenantId,
        );

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
