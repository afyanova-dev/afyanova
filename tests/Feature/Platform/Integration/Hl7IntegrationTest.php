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
    $secretResolver->setSecret('hl7_key', 'analyzer-secret-signing-key');

    /** @var SyntheticDomainDispatcher $dispatcher */
    $dispatcher = app(SyntheticDomainDispatcher::class);
    $dispatcher->clear();
});

test('translates raw pipe-delimited HL7 v2 ORU^R01 message into ImportExternalLabResult command', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector(
            $tenantId,
            name: 'Mindray BC-5000 Hematology',
            key: 'mindray-bc5000',
            family: ConnectorFamily::DIAGNOSTIC_ANALYZER,
            authType: AuthType::HMAC_SHA256,
        );
        createTestCredential($connector, 'hl7_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/hl7/mindray');

        $hl7Message = implode("\r\n", [
            'MSH|^~\&|MINDRAY|LAB|AFYANOVA|HOSP|20260912183000||ORU^R01|MSG20260912001|P|2.3.1',
            'PID|1||PAT-987654||DOE^JOHN||19850615|M',
            'OBR|1|ORD-5001|FILL-9001|CBC^Complete Blood Count|||20260912182000',
            'OBX|1|NM|WBC^White Blood Cells||7.4|10*3/uL|4.0-10.0|N|||F',
            'OBX|2|NM|RBC^Red Blood Cells||4.85|10*6/uL|4.5-5.9|N|||F',
            'OBX|3|NM|HGB^Hemoglobin||14.2|g/dL|13.5-17.5|N|||F',
            'OBX|4|NM|PLT^Platelets||135|10*3/uL|150-450|L|||F',
        ]);

        $payload = ['hl7_message' => $hl7Message];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, 'analyzer-secret-signing-key');

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => $signature],
            rawBody: $rawBody,
            parsedBody: $payload,
            externalRef: 'MSG20260912001',
            eventType: 'ORU_R01',
            correlationId: (string) Str::uuid(),
        );

        $result = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));

        expect($result->acknowledged)->toBeTrue()
            ->and($result->status)->toBe(InboundMessageStatus::ACK);

        /** @var InboundMessage $message */
        $message = app(QueryBus::class)->dispatch(new GetInboundMessageQuery($result->messageId));
        expect($message->translated_command_type)->toBe('ImportExternalLabResult');

        $translated = $message->translated_payload;
        expect($translated['message_control_id'])->toBe('MSG20260912001')
            ->and($translated['patient_identifier'])->toBe('PAT-987654')
            ->and($translated['order_reference'])->toBe('ORD-5001')
            ->and($translated['observations'])->toHaveCount(4)
            ->and($translated['observations'][0])->toMatchArray([
                'identifier' => 'WBC^White Blood Cells',
                'value' => '7.4',
                'units' => '10*3/uL',
                'reference_range' => '4.0-10.0',
                'abnormal_flag' => 'N',
                'result_status' => 'F',
            ])
            ->and($translated['observations'][3])->toMatchArray([
                'identifier' => 'PLT^Platelets',
                'value' => '135',
                'abnormal_flag' => 'L',
            ]);

        /** @var SyntheticDomainDispatcher $dispatcher */
        $dispatcher = app(SyntheticDomainDispatcher::class);
        expect($dispatcher->getDispatchedCommands())->toHaveCount(1)
            ->and($dispatcher->getDispatchedCommands()[0]['command_type'])->toBe('ImportExternalLabResult');
    });
});

test('translates structured JSON observations format from modern analyzer', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector(
            $tenantId,
            name: 'Sysmex XN-350',
            key: 'sysmex-xn350',
            family: ConnectorFamily::DIAGNOSTIC_ANALYZER,
            authType: AuthType::HMAC_SHA256,
        );
        createTestCredential($connector, 'hl7_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/hl7/sysmex');

        $payload = [
            'message_control_id' => 'SYS-2026-991',
            'patient_id' => 'PAT-112233',
            'order_reference' => 'LAB-REQ-8877',
            'observations' => [
                ['test' => 'GLU', 'value' => '5.6', 'unit' => 'mmol/L', 'flag' => 'N'],
                ['test' => 'CREAT', 'value' => '88', 'unit' => 'umol/L', 'flag' => 'N'],
            ],
        ];

        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, 'analyzer-secret-signing-key');

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => $signature],
            rawBody: $rawBody,
            parsedBody: $payload,
            externalRef: 'SYS-2026-991',
            eventType: 'LAB_RESULT',
            correlationId: (string) Str::uuid(),
        );

        $result = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));

        expect($result->acknowledged)->toBeTrue();

        /** @var InboundMessage $message */
        $message = app(QueryBus::class)->dispatch(new GetInboundMessageQuery($result->messageId));
        expect($message->translated_command_type)->toBe('ImportExternalLabResult')
            ->and($message->translated_payload['patient_identifier'])->toBe('PAT-112233')
            ->and($message->translated_payload['observations'])->toHaveCount(2);
    });
});

test('dead-letters invalid HL7 message missing MSH segment', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector(
            $tenantId,
            name: 'Broken Analyzer',
            key: 'broken-hl7',
            family: ConnectorFamily::DIAGNOSTIC_ANALYZER,
            authType: AuthType::HMAC_SHA256,
        );
        createTestCredential($connector, 'hl7_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/hl7/broken');

        $hl7Message = 'PID|1||PAT-999||NO_MSH^HERE';
        $payload = ['hl7_message' => $hl7Message];
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, 'analyzer-secret-signing-key');

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => $signature],
            rawBody: $rawBody,
            parsedBody: $payload,
            externalRef: 'BROKEN-HL7-01',
            eventType: 'ORU_R01',
            correlationId: (string) Str::uuid(),
        );

        $result = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));

        expect($result->acknowledged)->toBeFalse()
            ->and($result->status)->toBe(InboundMessageStatus::DEAD_LETTER);

        /** @var InboundMessage $message */
        $message = app(QueryBus::class)->dispatch(new GetInboundMessageQuery($result->messageId));
        expect($message->dead_letter_reason)->toContain('missing required MSH segment');
    });
});
