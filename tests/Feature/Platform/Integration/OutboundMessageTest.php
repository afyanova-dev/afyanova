<?php

declare(strict_types=1);

use App\Platform\Integration\Application\Command\ResolveDeadLetterCommand;
use App\Platform\Integration\Application\Command\RetryOutboundMessageCommand;
use App\Platform\Integration\Application\Command\SendOutboundMessageCommand;
use App\Platform\Integration\Contracts\Dto\OutboundEnqueueDto;
use App\Platform\Integration\Contracts\Fixture\SyntheticTransportClient;
use App\Platform\Integration\Domain\Enum\OutboundMessageStatus;
use App\Platform\Integration\Domain\Model\OutboundMessage;
use App\Shared\Bus\CommandBus;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/integration.php';

beforeEach(function () {
    $this->fixture = integrationFixture();
    /** @var SyntheticTransportClient $transport */
    $transport = app(SyntheticTransportClient::class);
    $transport->clear();
    $transport->setNextShouldFail(false);
});

test('enqueues and dispatches outbound message successfully', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId, 'SMS Gateway', 'sms-gw');

        $dto = new OutboundEnqueueDto(
            tenantId: $tenantId,
            connectorId: $connector->id,
            triggeredBy: 'CareCoordination:ReferralNotice',
            correlationId: (string) Str::uuid(),
            payload: ['phone' => '+255712345678', 'body' => 'Appointment scheduled'],
            recipientRef: 'https://sms.example.tz/dispatch',
            maxAttempts: 3,
        );

        /** @var OutboundMessage $message */
        $message = app(CommandBus::class)->dispatch(new SendOutboundMessageCommand($dto, dispatchNow: true));

        expect($message->status)->toBe(OutboundMessageStatus::SENT)
            ->and($message->attempts)->toBe(1)
            ->and($message->provider_ref)->toBe('SYNTH-OUT-999')
            ->and($message->last_response)->toMatchArray(['status' => 'ACCEPTED']);

        /** @var SyntheticTransportClient $transport */
        $transport = app(SyntheticTransportClient::class);
        expect($transport->getSentCalls())->toHaveCount(1)
            ->and($transport->getSentCalls()[0]['target_url'])->toBe('https://api.external-partner.example.com');
    });
});

test('handles transport delivery failure with exponential backoff retry scheduling', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId);

        /** @var SyntheticTransportClient $transport */
        $transport = app(SyntheticTransportClient::class);
        $transport->setNextShouldFail(true, 'Connection refused on port 443');

        $dto = new OutboundEnqueueDto(
            tenantId: $tenantId,
            connectorId: $connector->id,
            triggeredBy: 'LabOrderService',
            correlationId: (string) Str::uuid(),
            payload: ['specimen' => 'S-101'],
            maxAttempts: 3,
        );

        /** @var OutboundMessage $message */
        $message = app(CommandBus::class)->dispatch(new SendOutboundMessageCommand($dto, dispatchNow: true));

        expect($message->status)->toBe(OutboundMessageStatus::FAILED)
            ->and($message->attempts)->toBe(1)
            ->and($message->next_retry_at)->not->toBeNull()
            ->and($message->last_response)->toMatchArray(['error' => 'Connection refused on port 443']);

        // Now transport recovers and retry command is called
        $transport->setNextShouldFail(false);
        /** @var OutboundMessage $retriedMessage */
        $retriedMessage = app(CommandBus::class)->dispatch(new RetryOutboundMessageCommand($message->id));

        expect($retriedMessage->status)->toBe(OutboundMessageStatus::SENT)
            ->and($retriedMessage->attempts)->toBe(2);
    });
});

test('transitions to DEAD_LETTER when maximum delivery attempts are exhausted', function () {
    $tenantId = (string) $this->fixture['tenant']->id;

    inTenantContext($tenantId, function () use ($tenantId) {
        $connector = createTestConnector($tenantId);

        /** @var SyntheticTransportClient $transport */
        $transport = app(SyntheticTransportClient::class);
        $transport->setNextShouldFail(true, 'Persistent gateway error 500');

        $dto = new OutboundEnqueueDto(
            tenantId: $tenantId,
            connectorId: $connector->id,
            triggeredBy: 'ClinicalAlert',
            correlationId: (string) Str::uuid(),
            payload: ['alert' => 'critical'],
            maxAttempts: 2,
        );

        /** @var OutboundMessage $message */
        $message = app(CommandBus::class)->dispatch(new SendOutboundMessageCommand($dto, dispatchNow: true));
        expect($message->status)->toBe(OutboundMessageStatus::FAILED);

        // Second failed attempt (exhaustion)
        $exhausted = app(CommandBus::class)->dispatch(new RetryOutboundMessageCommand($message->id));
        expect($exhausted->status)->toBe(OutboundMessageStatus::DEAD_LETTER)
            ->and($exhausted->attempts)->toBe(2)
            ->and($exhausted->dead_letter_reason)->toBe('Persistent gateway error 500')
            ->and($exhausted->next_retry_at)->toBeNull();

        // Dead letter can be resolved manually by admin
        $resolved = app(CommandBus::class)->dispatch(new ResolveDeadLetterCommand(
            messageId: $exhausted->id,
            resolutionReason: 'Manually phoned external lab director; verified delivery out-of-band',
        ));

        expect($resolved->status)->toBe(OutboundMessageStatus::ACK)
            ->and($resolved->dead_letter_reason)->toContain('Manually phoned');
    });
});
