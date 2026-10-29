<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Command;

use App\Platform\Notifications\Contracts\DTO\BulkNotificationResponseDto;
use App\Shared\Bus\CommandBus;

final readonly class SendBulkNotificationHandler
{
    public function __construct(private CommandBus $commandBus) {}

    public function __invoke(SendBulkNotificationCommand $command): BulkNotificationResponseDto
    {
        $requestIds = [];

        foreach ($command->recipients as $recipient) {
            $dto = $this->commandBus->dispatch(new SendNotificationCommand(
                templateKey: $command->templateKey,
                recipientType: $recipient['type'],
                recipientId: $recipient['id'],
                onBehalfOfDomain: $command->onBehalfOfDomain,
                onBehalfOfEntity: $command->onBehalfOfEntity,
                context: $command->context,
                channels: $command->channels,
                priority: $command->priority,
                consentBasisRef: $command->consentBasisRef,
                templateVersion: $command->templateVersion,
            ));

            $requestIds[] = $dto->id;
        }

        return new BulkNotificationResponseDto(
            queuedCount: count($requestIds),
            requestIds: $requestIds,
        );
    }
}
