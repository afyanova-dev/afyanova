<?php

declare(strict_types=1);

use App\Platform\Integration\Application\Command\ReceiveInboundMessageCommand;
use App\Platform\Integration\Application\Command\ReplayInboundMessageCommand;
use App\Platform\Integration\Application\Query\GetInboundMessageQuery;
use App\Platform\Integration\Contracts\Dto\InboundWebhookRequestDto;
use App\Platform\Integration\Contracts\Dto\InboundWebhookResultDto;
use App\Platform\Integration\Contracts\Fixture\SyntheticDomainDispatcher;
use App\Platform\Integration\Domain\Enum\AuthType;
use App\Platform\Integration\Domain\Enum\InboundMessageStatus;
use App\Platform\Integration\Domain\Exception\AuthenticationFailedException;
use App\Platform\Integration\Domain\Model\InboundMessage;
use App\Platform\Integration\Infrastructure\Secret\ConfigSecretResolver;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/integration.php';

beforeEach(function () {
    $this->fixture = integrationFixture();
    /** @var ConfigSecretResolver $secretResolver */
    $secretResolver = app(ConfigSecretResolver::class);
    $secretResolver->setSecret('lab_secret_key', 'test-signing-secret-12345');
    $secretResolver->setSecret('bearer_secret_key', 'test-bearer-token-abcde');

    /** @var SyntheticDomainDispatcher $dispatcher */
    $dispatcher = app(SyntheticDomainDispatcher::class);
    $dispatcher->clear();
});

test('processes valid HMAC-SHA256 webhook through airlock to domain dispatch', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId, 'Lab Analyzer', 'lab-analyzer');
        createTestCredential($connector, 'lab_secret_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/lab-results');

        $payload = [
            'specimen_id' => 'SPEC-99881',
            'result_code' => 'HEMO-POS',
            'reading' => 14.5,
        ];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, 'test-signing-secret-12345');

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => $signature],
            rawBody: $rawBody,
            parsedBody: $payload,
            externalRef: 'EXT-MSG-001',
            eventType: 'LAB_RESULT',
            correlationId: (string) Str::uuid(),
        );

        /** @var InboundWebhookResultDto $result */
        $result = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));

        expect($result->acknowledged)->toBeTrue()
            ->and($result->status)->toBe(InboundMessageStatus::ACK);

        // Verify stored message in database
        /** @var InboundMessage|null $message */
        $message = app(QueryBus::class)->dispatch(new GetInboundMessageQuery($result->messageId));
        expect($message)->not->toBeNull()
            ->and($message->signature_valid)->toBeTrue()
            ->and($message->status)->toBe(InboundMessageStatus::ACK)
            ->and($message->translated_command_type)->toBe('RecordSyntheticEvent')
            ->and($message->dispatched_at)->not->toBeNull();

        // Verify synthetic domain received command
        /** @var SyntheticDomainDispatcher $dispatcher */
        $dispatcher = app(SyntheticDomainDispatcher::class);
        expect($dispatcher->getDispatchedCommands())->toHaveCount(1)
            ->and($dispatcher->getDispatchedCommands()[0]['command_type'])->toBe('RecordSyntheticEvent')
            ->and($dispatcher->getDispatchedCommands()[0]['tenant_id'])->toBe($tenantId);
    });
});

test('rejects inbound webhook with invalid HMAC signature (fail-closed)', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId);
        createTestCredential($connector, 'lab_secret_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/lab-results');

        $payload = ['data' => 123];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => 'invalid-signature-hash'],
            rawBody: $rawBody,
            parsedBody: $payload,
            externalRef: 'EXT-MSG-FAIL',
            eventType: 'LAB_RESULT',
            correlationId: (string) Str::uuid(),
        );

        expect(fn () => app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto)))
            ->toThrow(AuthenticationFailedException::class, 'Invalid webhook signature or credentials');
    });
});

test('authenticates bearer token webhook', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId, authType: AuthType::BEARER_TOKEN);
        createTestCredential($connector, 'bearer_secret_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/telemetry');

        $payload = ['metric' => 'heart_rate', 'value' => 72];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['Authorization' => 'Bearer test-bearer-token-abcde'],
            rawBody: $rawBody,
            parsedBody: $payload,
            externalRef: 'EXT-MSG-BEARER-1',
            eventType: 'TELEMETRY',
            correlationId: (string) Str::uuid(),
        );

        /** @var InboundWebhookResultDto $result */
        $result = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));

        expect($result->acknowledged)->toBeTrue()
            ->and($result->status)->toBe(InboundMessageStatus::ACK);
    });
});

test('deduplicates identical external references idempotently', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId);
        createTestCredential($connector, 'lab_secret_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/idempotent');

        $payload = ['event' => 'ping'];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, 'test-signing-secret-12345');

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => $signature],
            rawBody: $rawBody,
            parsedBody: $payload,
            externalRef: 'UNIQUE-IDEMP-REF-77',
            eventType: 'PING',
            correlationId: (string) Str::uuid(),
        );

        // First arrival
        $firstResult = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));
        expect($firstResult->acknowledged)->toBeTrue();

        // Second arrival with same externalRef
        $secondResult = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));
        expect($secondResult->acknowledged)->toBeTrue()
            ->and($secondResult->messageId)->toBe($firstResult->messageId)
            ->and($secondResult->reason)->toContain('Duplicate external reference already processed');

        // Only one dispatch to synthetic domain
        /** @var SyntheticDomainDispatcher $dispatcher */
        $dispatcher = app(SyntheticDomainDispatcher::class);
        expect($dispatcher->getDispatchedCommands())->toHaveCount(1);
    });
});

test('dead-letters message when translation fails and allows replay', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId);
        createTestCredential($connector, 'lab_secret_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/translation-test');

        $payload = ['fail_translation' => true, 'data' => 'corrupt'];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, 'test-signing-secret-12345');

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => $signature],
            rawBody: $rawBody,
            parsedBody: $payload,
            externalRef: 'EXT-FAIL-TRANS',
            eventType: 'CORRUPT',
            correlationId: (string) Str::uuid(),
        );

        $result = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));
        expect($result->acknowledged)->toBeFalse()
            ->and($result->status)->toBe(InboundMessageStatus::DEAD_LETTER);

        /** @var InboundMessage $message */
        $message = app(QueryBus::class)->dispatch(new GetInboundMessageQuery($result->messageId));
        expect($message->status)->toBe(InboundMessageStatus::DEAD_LETTER)
            ->and($message->canReplay())->toBeTrue();

        // Now fix payload for replay
        $message->raw_payload = ['fail_translation' => false, 'data' => 'corrected'];
        $message->save();

        // Replay message
        $replayResult = app(CommandBus::class)->dispatch(new ReplayInboundMessageCommand($message->id));
        expect($replayResult->acknowledged)->toBeTrue()
            ->and($replayResult->status)->toBe(InboundMessageStatus::ACK);

        $message->refresh();
        expect($message->status)->toBe(InboundMessageStatus::ACK);
    });
});

test('sanitizes sensitive fields prior to storing raw payload', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId);
        createTestCredential($connector, 'lab_secret_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/sanitize-test');

        $payload = [
            'patient_external_id' => 'EXT-P-99',
            'password' => 'super_secret_pw',
            'auth_token' => 'jwt_secret_token',
            'meta' => [
                'api_key' => 'live_ak_123456789',
                'status' => 'OK',
            ],
        ];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, 'test-signing-secret-12345');

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => $signature],
            rawBody: $rawBody,
            parsedBody: $payload,
            externalRef: 'EXT-SENSITIVE-1',
            eventType: 'PATIENT_DATA',
            correlationId: (string) Str::uuid(),
        );

        $result = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));
        expect($result->acknowledged)->toBeTrue();

        /** @var InboundMessage $message */
        $message = app(QueryBus::class)->dispatch(new GetInboundMessageQuery($result->messageId));
        expect($message->raw_payload['patient_external_id'])->toBe('EXT-P-99')
            ->and($message->raw_payload['password'])->toBe('***REDACTED***')
            ->and($message->raw_payload['auth_token'])->toBe('***REDACTED***')
            ->and($message->raw_payload['meta']['api_key'])->toBe('***REDACTED***')
            ->and($message->raw_payload['meta']['status'])->toBe('OK');
    });
});
