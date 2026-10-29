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
    $secretResolver->setSecret('pacs_key', 'orthanc-pacs-shared-secret');

    /** @var SyntheticDomainDispatcher $dispatcher */
    $dispatcher = app(SyntheticDomainDispatcher::class);
    $dispatcher->clear();
});

test('translates Orthanc / DICOM Web study availability into RecordExternalImagingStudy command', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector(
            $tenantId,
            name: 'Orthanc PACS Server',
            key: 'orthanc-pacs',
            family: ConnectorFamily::DIAGNOSTIC_ANALYZER,
            authType: AuthType::HMAC_SHA256,
        );
        createTestCredential($connector, 'pacs_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/dicom/orthanc');

        $dicomPayload = [
            'ID' => 'a98b76-cd54-3210-efba-orthancid',
            'StudyInstanceUID' => '1.2.840.113619.2.55.3.60468842.671.1234567890.100',
            'PatientID' => 'PAT-RAD-9921',
            'AccessionNumber' => 'ACC-XR-501',
            'Modality' => 'CR',
            'StudyDate' => '20260912',
            'SeriesCount' => 2,
            'InstancesCount' => 4,
            'WadoUrl' => 'https://pacs.example.com/dicom-web/studies/1.2.840.113619.2.55.3.60468842.671.1234567890.100',
        ];

        $rawBody = json_encode($dicomPayload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, 'orthanc-pacs-shared-secret');

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => $signature],
            rawBody: $rawBody,
            parsedBody: $dicomPayload,
            externalRef: '1.2.840.113619.2.55.3.60468842.671.1234567890.100',
            eventType: 'IMAGING_STUDY',
            correlationId: (string) Str::uuid(),
        );

        $result = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));

        expect($result->acknowledged)->toBeTrue()
            ->and($result->status)->toBe(InboundMessageStatus::ACK);

        /** @var InboundMessage $message */
        $message = app(QueryBus::class)->dispatch(new GetInboundMessageQuery($result->messageId));
        expect($message->translated_command_type)->toBe('RecordExternalImagingStudy')
            ->and($message->translated_payload)->toMatchArray([
                'study_instance_uid' => '1.2.840.113619.2.55.3.60468842.671.1234567890.100',
                'accession_number' => 'ACC-XR-501',
                'patient_identifier' => 'PAT-RAD-9921',
                'modality' => 'CR',
                'series_count' => 2,
                'instances_count' => 4,
                'study_date' => '20260912',
                'wado_url' => 'https://pacs.example.com/dicom-web/studies/1.2.840.113619.2.55.3.60468842.671.1234567890.100',
            ]);

        /** @var SyntheticDomainDispatcher $dispatcher */
        $dispatcher = app(SyntheticDomainDispatcher::class);
        expect($dispatcher->getDispatchedCommands())->toHaveCount(1)
            ->and($dispatcher->getDispatchedCommands()[0]['command_type'])->toBe('RecordExternalImagingStudy');
    });
});

test('dead-letters DICOM payload missing StudyInstanceUID', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector(
            $tenantId,
            name: 'Orthanc PACS Incomplete',
            key: 'orthanc-incomplete',
            family: ConnectorFamily::DIAGNOSTIC_ANALYZER,
            authType: AuthType::HMAC_SHA256,
        );
        createTestCredential($connector, 'pacs_key');
        $endpoint = createTestWebhookEndpoint($connector, 'webhooks/dicom/incomplete');

        $dicomPayload = [
            'Modality' => 'CT',
            'PatientID' => 'PAT-CT-12',
        ];

        $rawBody = json_encode($dicomPayload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $rawBody, 'orthanc-pacs-shared-secret');

        $requestDto = new InboundWebhookRequestDto(
            tenantId: $tenantId,
            pathSlug: $endpoint->path_slug,
            headers: ['X-Signature' => $signature],
            rawBody: $rawBody,
            parsedBody: $dicomPayload,
            externalRef: 'STUDY-MISSING-UID',
            eventType: 'IMAGING_STUDY',
            correlationId: (string) Str::uuid(),
        );

        $result = app(CommandBus::class)->dispatch(new ReceiveInboundMessageCommand($requestDto));

        expect($result->acknowledged)->toBeFalse()
            ->and($result->status)->toBe(InboundMessageStatus::DEAD_LETTER);

        /** @var InboundMessage $message */
        $message = app(QueryBus::class)->dispatch(new GetInboundMessageQuery($result->messageId));
        expect($message->dead_letter_reason)->toContain('missing StudyInstanceUID');
    });
});
