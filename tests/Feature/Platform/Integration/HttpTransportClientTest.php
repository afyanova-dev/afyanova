<?php

declare(strict_types=1);

use App\Platform\Integration\Domain\Enum\OutboundMessageStatus;
use App\Platform\Integration\Domain\Model\OutboundMessage;
use App\Platform\Integration\Infrastructure\Transport\HttpTransportClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('dispatches outbound message via HTTP client and parses success response', function () {
    Http::fake([
        'https://api.external.example.com/v1/orders' => Http::response([
            'id' => 'EXT-ORD-9988',
            'status' => 'ACCEPTED',
        ], 200),
    ]);

    $client = new HttpTransportClient(timeout: 5);

    $message = new OutboundMessage([
        'tenant_id' => (string) Str::uuid(),
        'connector_id' => (string) Str::uuid(),
        'triggered_by' => 'Pharmacy:DispenseOrder',
        'correlation_id' => (string) Str::uuid(),
        'recipient_ref' => 'https://api.external.example.com/v1/orders',
        'payload' => ['item' => 'Amoxicillin 500mg', 'quantity' => 20],
        'status' => OutboundMessageStatus::SENDING,
        'attempts' => 1,
        'max_attempts' => 3,
    ]);
    $message->id = (string) Str::uuid();

    $result = $client->send(
        message: $message,
        targetUrl: 'https://api.external.example.com/v1/orders',
        headers: ['X-Custom-Auth' => 'Bearer token-123'],
    );

    expect($result['success'])->toBeTrue()
        ->and($result['provider_ref'])->toBe('EXT-ORD-9988')
        ->and($result['response'])->toMatchArray(['status' => 'ACCEPTED'])
        ->and($result['error'])->toBeNull();

    Http::assertSent(function ($request) use ($message) {
        return $request->url() === 'https://api.external.example.com/v1/orders'
            && $request->header('X-Correlation-ID')[0] === $message->correlation_id
            && $request->header('X-Custom-Auth')[0] === 'Bearer token-123'
            && $request['item'] === 'Amoxicillin 500mg';
    });
});

test('handles remote 500 HTTP server error without throwing exception', function () {
    Http::fake([
        'https://api.external.example.com/v1/orders' => Http::response('Internal Server Error', 500),
    ]);

    $client = new HttpTransportClient(timeout: 5);

    $message = new OutboundMessage([
        'tenant_id' => (string) Str::uuid(),
        'connector_id' => (string) Str::uuid(),
        'triggered_by' => 'Lab:ExternalSpecimen',
        'correlation_id' => (string) Str::uuid(),
        'recipient_ref' => 'https://api.external.example.com/v1/orders',
        'payload' => ['specimen' => 'SPEC-1'],
        'status' => OutboundMessageStatus::SENDING,
        'attempts' => 1,
        'max_attempts' => 3,
    ]);
    $message->id = (string) Str::uuid();

    $result = $client->send(
        message: $message,
        targetUrl: 'https://api.external.example.com/v1/orders',
    );

    expect($result['success'])->toBeFalse()
        ->and($result['provider_ref'])->toBeNull()
        ->and($result['error'])->toContain('HTTP 500');
});
