<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Pipeline;

use App\Platform\Integration\Contracts\Dto\InboundWebhookRequestDto;
use App\Platform\Integration\Contracts\Dto\InboundWebhookResultDto;
use App\Platform\Integration\Contracts\Port\DomainDispatcherPort;
use App\Platform\Integration\Contracts\Port\InboundTranslatorPort;
use App\Platform\Integration\Contracts\Port\PayloadSanitizerPort;
use App\Platform\Integration\Contracts\Port\WebhookAuthenticatorPort;
use App\Platform\Integration\Domain\Enum\DispatchStatus;
use App\Platform\Integration\Domain\Enum\InboundMessageStatus;
use App\Platform\Integration\Domain\Exception\AuthenticationFailedException;
use App\Platform\Integration\Domain\Exception\ConnectorDisabledException;
use App\Platform\Integration\Domain\Exception\ConnectorNotFoundException;
use App\Platform\Integration\Domain\Exception\DuplicateMessageException;
use App\Platform\Integration\Domain\Exception\ReplayNotAllowedException;
use App\Platform\Integration\Domain\Exception\TenantMismatchException;
use App\Platform\Integration\Domain\Exception\TranslationFailedException;
use App\Platform\Integration\Domain\Model\InboundMessage;
use App\Platform\Integration\Domain\Repository\ConnectorRepository;
use App\Platform\Integration\Domain\Repository\CredentialRepository;
use App\Platform\Integration\Domain\Repository\InboundMessageRepository;
use App\Platform\Integration\Domain\Repository\WebhookEndpointRepository;
use Carbon\CarbonImmutable;
use Throwable;

final readonly class InboundAirlockPipeline
{
    public function __construct(
        private WebhookEndpointRepository $endpointRepository,
        private ConnectorRepository $connectorRepository,
        private CredentialRepository $credentialRepository,
        private InboundMessageRepository $messageRepository,
        private WebhookAuthenticatorPort $authenticator,
        private PayloadSanitizerPort $sanitizer,
        private InboundTranslatorPort $translator,
        private DomainDispatcherPort $domainDispatcher,
    ) {}

    public function process(InboundWebhookRequestDto $request): InboundWebhookResultDto
    {
        // 1. Resolve webhook endpoint
        $endpoint = $this->endpointRepository->findByPath($request->pathSlug);
        if ($endpoint === null || ! $endpoint->active) {
            throw new ConnectorNotFoundException("Webhook endpoint '{$request->pathSlug}' not found or inactive");
        }

        // 2. Resolve connector
        $connector = $this->connectorRepository->findById($endpoint->connector_id);
        if ($connector === null) {
            throw new ConnectorNotFoundException("Connector '{$endpoint->connector_id}' not found");
        }

        if (! $connector->status->isOperable()) {
            throw new ConnectorDisabledException("Connector '{$connector->name}' is {$connector->status->value}");
        }

        // 3. Strict single-tenant binding check (INV-4)
        if ($connector->tenant_id !== $endpoint->tenant_id) {
            throw new TenantMismatchException('Endpoint and connector tenant mismatch');
        }

        // 4. Authenticate credential (fail-closed)
        $credential = $this->credentialRepository->findActiveByConnector($connector->id);
        if ($credential === null) {
            throw new AuthenticationFailedException("No active credentials configured for connector '{$connector->name}'");
        }

        $authenticated = $this->authenticator->authenticate($credential, $request->headers, $request->rawBody);
        if (! $authenticated) {
            throw new AuthenticationFailedException('Invalid webhook signature or credentials');
        }

        // 5. Deduplication check (INV-5)
        $existingMessage = $this->messageRepository->findByExternalRef($connector->id, $request->externalRef);
        if ($existingMessage !== null) {
            if ($existingMessage->status === InboundMessageStatus::ACK) {
                return new InboundWebhookResultDto(
                    messageId: $existingMessage->id,
                    status: $existingMessage->status,
                    acknowledged: true,
                    domainOutcome: $existingMessage->domain_outcome_reason,
                    reason: 'Duplicate external reference already processed and acknowledged',
                );
            }

            throw new DuplicateMessageException("Message with external reference '{$request->externalRef}' already received");
        }

        // 6. Record inbound message
        $sanitizedPayload = $this->sanitizer->sanitize($request->parsedBody);
        $message = new InboundMessage([
            'tenant_id' => $connector->tenant_id,
            'connector_id' => $connector->id,
            'external_ref' => $request->externalRef,
            'correlation_id' => $request->correlationId,
            'causation_id' => $request->causationId,
            'signature_valid' => true,
            'raw_payload' => $sanitizedPayload,
            'status' => InboundMessageStatus::AUTHENTICATED,
            'dispatch_status' => DispatchStatus::PENDING,
            'received_at' => CarbonImmutable::now(),
        ]);
        $this->messageRepository->save($message);

        // 7. Translate payload
        try {
            $translated = $this->translator->translate($message);
            $message->markTranslated($translated['command_type'], $translated['payload']);
            $this->messageRepository->save($message);
        } catch (TranslationFailedException $e) {
            $message->deadLetter('Translation failed: '.$e->getMessage());
            $this->messageRepository->save($message);

            return new InboundWebhookResultDto(
                messageId: $message->id,
                status: InboundMessageStatus::DEAD_LETTER,
                acknowledged: false,
                reason: $e->getMessage(),
            );
        }

        // 8. Dispatch to domain boundary
        try {
            $message->markDispatched();
            $this->messageRepository->save($message);

            $dispatchOutcome = $this->domainDispatcher->dispatch(
                $message->translated_command_type ?? '',
                $message->translated_payload ?? [],
                $connector->tenant_id,
            );

            if ($dispatchOutcome['status'] === 'COMMITTED') {
                $message->markAck($dispatchOutcome['reason']);
            } else {
                $message->markRejected($dispatchOutcome['reason'] ?? 'Domain rejected command');
            }

            $this->messageRepository->save($message);

            return new InboundWebhookResultDto(
                messageId: $message->id,
                status: $message->status,
                acknowledged: $message->status === InboundMessageStatus::ACK,
                domainOutcome: $message->domain_outcome_reason,
            );
        } catch (Throwable $e) {
            $message->deadLetter('Domain dispatch failed: '.$e->getMessage());
            $this->messageRepository->save($message);

            return new InboundWebhookResultDto(
                messageId: $message->id,
                status: InboundMessageStatus::DEAD_LETTER,
                acknowledged: false,
                reason: $e->getMessage(),
            );
        }
    }

    public function replay(string $messageId): InboundWebhookResultDto
    {
        $message = $this->messageRepository->findById($messageId);
        if ($message === null) {
            throw new ConnectorNotFoundException("Message '{$messageId}' not found");
        }

        if (! $message->canReplay()) {
            throw new ReplayNotAllowedException("Message '{$messageId}' cannot be replayed from status {$message->status->value}");
        }

        // Retranslate
        try {
            $translated = $this->translator->translate($message);
            $message->markTranslated($translated['command_type'], $translated['payload']);
            $message->markDispatched();
            $this->messageRepository->save($message);

            $dispatchOutcome = $this->domainDispatcher->dispatch(
                $message->translated_command_type ?? '',
                $message->translated_payload ?? [],
                $message->tenant_id,
            );

            if ($dispatchOutcome['status'] === 'COMMITTED') {
                $message->markAck('Replay: '.($dispatchOutcome['reason'] ?? 'Success'));
            } else {
                $message->markRejected('Replay: '.($dispatchOutcome['reason'] ?? 'Domain rejected'));
            }

            $this->messageRepository->save($message);

            return new InboundWebhookResultDto(
                messageId: $message->id,
                status: $message->status,
                acknowledged: $message->status === InboundMessageStatus::ACK,
                domainOutcome: $message->domain_outcome_reason,
            );
        } catch (Throwable $e) {
            $message->deadLetter('Replay failed: '.$e->getMessage());
            $this->messageRepository->save($message);

            return new InboundWebhookResultDto(
                messageId: $message->id,
                status: InboundMessageStatus::DEAD_LETTER,
                acknowledged: false,
                reason: $e->getMessage(),
            );
        }
    }
}
