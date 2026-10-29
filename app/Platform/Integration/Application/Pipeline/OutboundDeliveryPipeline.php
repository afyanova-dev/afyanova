<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Pipeline;

use App\Platform\Integration\Contracts\Dto\OutboundEnqueueDto;
use App\Platform\Integration\Contracts\Port\IntegrationTransportClient;
use App\Platform\Integration\Contracts\Port\PayloadSanitizerPort;
use App\Platform\Integration\Domain\Enum\OutboundMessageStatus;
use App\Platform\Integration\Domain\Exception\ConnectorDisabledException;
use App\Platform\Integration\Domain\Exception\ConnectorNotFoundException;
use App\Platform\Integration\Domain\Model\OutboundMessage;
use App\Platform\Integration\Domain\Repository\ConnectorRepository;
use App\Platform\Integration\Domain\Repository\OutboundMessageRepository;

final readonly class OutboundDeliveryPipeline
{
    public function __construct(
        private ConnectorRepository $connectorRepository,
        private OutboundMessageRepository $messageRepository,
        private IntegrationTransportClient $transportClient,
        private PayloadSanitizerPort $sanitizer,
    ) {}

    public function enqueue(OutboundEnqueueDto $dto): OutboundMessage
    {
        $connector = $this->connectorRepository->findById($dto->connectorId);
        if ($connector === null) {
            throw new ConnectorNotFoundException("Connector '{$dto->connectorId}' not found");
        }

        if (! $connector->status->isOperable()) {
            throw new ConnectorDisabledException("Connector '{$connector->name}' is {$connector->status->value}");
        }

        $sanitizedPayload = $this->sanitizer->sanitize($dto->payload);

        $message = new OutboundMessage([
            'tenant_id' => $dto->tenantId,
            'connector_id' => $dto->connectorId,
            'triggered_by' => $dto->triggeredBy,
            'correlation_id' => $dto->correlationId,
            'causation_id' => $dto->causationId,
            'recipient_ref' => $dto->recipientRef,
            'payload' => $sanitizedPayload,
            'attempts' => 0,
            'max_attempts' => $dto->maxAttempts,
            'status' => OutboundMessageStatus::QUEUED,
        ]);

        $this->messageRepository->save($message);

        return $message;
    }

    public function dispatch(string $messageId): OutboundMessage
    {
        $message = $this->messageRepository->findById($messageId);
        if ($message === null) {
            throw new ConnectorNotFoundException("Outbound message '{$messageId}' not found");
        }

        $connector = $this->connectorRepository->findById($message->connector_id);
        if ($connector === null) {
            throw new ConnectorNotFoundException("Connector '{$message->connector_id}' not found");
        }

        if (! $connector->status->isOperable()) {
            throw new ConnectorDisabledException("Connector '{$connector->name}' is {$connector->status->value}");
        }

        $message->markSending();
        $targetUrl = $connector->base_url ?? $message->recipient_ref;

        if ($targetUrl === null || trim($targetUrl) === '') {
            throw new ConnectorDisabledException("Connector '{$connector->name}' has no base_url configured and message '{$messageId}' specifies no recipient_ref");
        }

        $result = $this->transportClient->send(
            message: $message,
            targetUrl: $targetUrl,
            headers: [
                'X-Correlation-ID' => $message->correlation_id,
                'Content-Type' => 'application/json',
            ],
        );

        if ($result['success']) {
            $message->markSent($result['provider_ref'], $result['response']);
        } else {
            $message->recordFailure($result['error'] ?? 'Transport delivery failed', $result['response']);
        }

        $this->messageRepository->save($message);

        return $message;
    }

    public function retry(string $messageId): OutboundMessage
    {
        $message = $this->messageRepository->findById($messageId);
        if ($message === null) {
            throw new ConnectorNotFoundException("Outbound message '{$messageId}' not found");
        }

        if ($message->status !== OutboundMessageStatus::FAILED && $message->status !== OutboundMessageStatus::DEAD_LETTER) {
            return $message;
        }

        // Reset status to QUEUED for retry
        $message->status = OutboundMessageStatus::QUEUED;
        $message->next_retry_at = null;
        $this->messageRepository->save($message);

        return $this->dispatch($messageId);
    }

    public function resolveDeadLetter(string $messageId, string $resolutionReason): OutboundMessage
    {
        $message = $this->messageRepository->findById($messageId);
        if ($message === null) {
            throw new ConnectorNotFoundException("Outbound message '{$messageId}' not found");
        }

        $message->status = OutboundMessageStatus::ACK;
        $message->dead_letter_reason = 'Resolved: '.$resolutionReason;
        $this->messageRepository->save($message);

        return $message;
    }
}
