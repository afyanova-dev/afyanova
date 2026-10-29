<?php

declare(strict_types=1);

use App\Platform\Integration\Application\Command\ReceiveInboundMessageCommand;
use App\Platform\Integration\Application\Query\GetInboundMessageQuery;
use App\Platform\Integration\Contracts\Dto\InboundWebhookRequestDto;
use App\Platform\Integration\Contracts\Fixture\SyntheticDomainDispatcher;
use App\Platform\Integration\Domain\Enum\AuthType;
use App\Platform\Integration\Domain\Enum\ConnectorFamily;
use App\Platform\Integration\Domain\Enum\InboundMessageStatus;
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
    $secretResolver->setSecret('mpesa_key', 'daraja-secret-key-xyz');

    /** @var SyntheticDomainDispatcher $dispatcher */
    $dispatcher = app(SyntheticDomainDispatcher::class);
    $dispatcher->clear();
});

test('translates Daraja C2B confirmation payload into RecordExternalPayment command', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector(
            $tenantId,
            name: 'Safaricom M-Pesa Daraja',
            key: 'mpesa-daraja',
            family: ConnectorFamily::PAYMENT_GATEWAY,
            authType: AuthType::HMAC_SHA256,
        );
        createTestCredential($connector, 'mpesa_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/mpesa/c2b');

        $c2bPayload = [
            'TransactionType' => 'Pay Bill',
            'TransID' => 'RKTQDM7W6S',
            'TransTime' => '20260912183000',
            'TransAmount' => '2500.00',
            'BusinessShortCode' => '600638',
            'BillRefNumber' => 'INV-2026-0042',
            'InvoiceNumber' => '',
            'OrgAccountBalance' => '150000.00',
            'ThirdPartyTransID' => '',
            'MSISDN' => '254712345678',
            'FirstName' => 'John',
        ];

        $rawBody = json_encode($c2bPayload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, 'daraja-secret-key-xyz');

        $correlationId = (string) Str::uuid();

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => $signature],
            rawBody: $rawBody,
            parsedBody: $c2bPayload,
            externalRef: 'RKTQDM7W6S',
            eventType: 'C2B_PAYMENT',
            correlationId: $correlationId,
        );

        $result = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));

        expect($result->acknowledged)->toBeTrue()
            ->and($result->status)->toBe(InboundMessageStatus::ACK);

        /** @var InboundMessage $message */
        $message = app(QueryBus::class)->dispatch(new GetInboundMessageQuery($result->messageId));
        expect($message->translated_command_type)->toBe('RecordExternalPayment')
            ->and($message->translated_payload)->toMatchArray([
                'provider' => 'MPESA',
                'channel' => 'C2B',
                'transaction_reference' => 'RKTQDM7W6S',
                'amount' => 2500.0,
                'msisdn' => '254712345678',
                'bill_reference' => 'INV-2026-0042',
                'is_successful' => true,
            ]);

        /** @var SyntheticDomainDispatcher $dispatcher */
        $dispatcher = app(SyntheticDomainDispatcher::class);
        expect($dispatcher->getDispatchedCommands())->toHaveCount(1)
            ->and($dispatcher->getDispatchedCommands()[0]['command_type'])->toBe('RecordExternalPayment')
            ->and($dispatcher->getDispatchedCommands()[0]['payload']['amount'])->toEqual(2500);
    });
});

test('translates Daraja STK Push callback payload into RecordExternalPayment command', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector(
            $tenantId,
            name: 'Safaricom M-Pesa STK',
            key: 'mpesa-stk',
            family: ConnectorFamily::PAYMENT_GATEWAY,
            authType: AuthType::HMAC_SHA256,
        );
        createTestCredential($connector, 'mpesa_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/mpesa/stk');

        $stkPayload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '29115-34620561-1',
                    'CheckoutRequestID' => 'ws_CO_12092026183000',
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 1200.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'RKX99182AB'],
                            ['Name' => 'TransactionDate', 'Value' => 20260912183015],
                            ['Name' => 'PhoneNumber', 'Value' => 254799887766],
                        ],
                    ],
                ],
            ],
        ];

        $rawBody = json_encode($stkPayload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, 'daraja-secret-key-xyz');
        $correlationId = (string) Str::uuid();

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => $signature],
            rawBody: $rawBody,
            parsedBody: $stkPayload,
            externalRef: 'ws_CO_12092026183000',
            eventType: 'STK_CALLBACK',
            correlationId: $correlationId,
        );

        $result = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));

        expect($result->acknowledged)->toBeTrue();

        /** @var InboundMessage $message */
        $message = app(QueryBus::class)->dispatch(new GetInboundMessageQuery($result->messageId));
        expect($message->translated_command_type)->toBe('RecordExternalPayment')
            ->and($message->translated_payload)->toMatchArray([
                'provider' => 'MPESA',
                'channel' => 'STK_PUSH',
                'transaction_reference' => 'RKX99182AB',
                'amount' => 1200.0,
                'msisdn' => '254799887766',
                'checkout_request_id' => 'ws_CO_12092026183000',
                'result_code' => 0,
                'is_successful' => true,
            ]);
    });
});

test('dead-letters unparseable M-Pesa payload missing both TransID and stkCallback', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector(
            $tenantId,
            name: 'Mpesa Corrupt',
            key: 'mpesa-corrupt',
            family: ConnectorFamily::PAYMENT_GATEWAY,
            authType: AuthType::HMAC_SHA256,
        );
        createTestCredential($connector, 'mpesa_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/mpesa/corrupt');

        $corruptPayload = [
            'foo' => 'bar',
            'status' => 'unknown',
        ];

        $rawBody = json_encode($corruptPayload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, 'daraja-secret-key-xyz');

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => $signature],
            rawBody: $rawBody,
            parsedBody: $corruptPayload,
            externalRef: 'CORRUPT-MPESA-01',
            eventType: 'C2B_PAYMENT',
            correlationId: (string) Str::uuid(),
        );

        $result = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));

        expect($result->acknowledged)->toBeFalse()
            ->and($result->status)->toBe(InboundMessageStatus::DEAD_LETTER);

        /** @var InboundMessage $message */
        $message = app(QueryBus::class)->dispatch(new GetInboundMessageQuery($result->messageId));
        expect($message->status)->toBe(InboundMessageStatus::DEAD_LETTER)
            ->and($message->dead_letter_reason)->toContain('Unrecognized M-Pesa payload structure');
    });
});
