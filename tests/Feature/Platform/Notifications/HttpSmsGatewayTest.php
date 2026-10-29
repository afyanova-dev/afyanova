<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Notifications;

use App\Platform\Notifications\Domain\Port\SmsGatewayPort;
use App\Platform\Notifications\Infrastructure\Adapter\HttpSmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

test('HttpSmsGateway normalizes phone numbers to standard E.164 format', function (): void {
    $gateway = new HttpSmsGateway(defaultCountryCode: '+254');

    expect($gateway->normalizePhone('0712345678'))->toBe('+254712345678');
    expect($gateway->normalizePhone('0112345678'))->toBe('+254112345678');
    expect($gateway->normalizePhone('0712 345 678'))->toBe('+254712345678');
    expect($gateway->normalizePhone('+254712345678'))->toBe('+254712345678');
    expect($gateway->normalizePhone('254712345678'))->toBe('+254712345678');
    expect($gateway->normalizePhone('+1 (415) 555-2671'))->toBe('+14155552671');
});

test('HttpSmsGateway successfully dispatches SMS payload to remote HTTP endpoint', function (): void {
    Http::fake([
        'https://api.sms-gateway.test/v1/messages' => Http::response([
            'status' => 'success',
            'message_id' => 'MSG-PROV-98765',
        ], 200),
    ]);

    $gateway = new HttpSmsGateway(
        endpoint: 'https://api.sms-gateway.test/v1/messages',
        apiKey: 'test-secret-key',
        defaultSenderId: 'AFYANOVA',
    );

    $result = $gateway->sendSms('0722123456', 'Your appointment is confirmed for tomorrow.');

    expect($result->success)->toBeTrue();
    expect($result->providerMessageId)->toBe('MSG-PROV-98765');
    expect($result->errorCode)->toBeNull();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.sms-gateway.test/v1/messages'
            && $request->hasHeader('Authorization', 'Bearer test-secret-key')
            && $request['to'] === '+254722123456'
            && $request['from'] === 'AFYANOVA'
            && $request['message'] === 'Your appointment is confirmed for tomorrow.';
    });
});

test('HttpSmsGateway handles provider HTTP error response cleanly', function (): void {
    Http::fake([
        'https://api.sms-gateway.test/v1/messages' => Http::response([
            'error' => 'Insufficient account balance',
        ], 402),
    ]);

    $gateway = new HttpSmsGateway(
        endpoint: 'https://api.sms-gateway.test/v1/messages',
        apiKey: 'test-key',
    );

    $result = $gateway->sendSms('+254712345678', 'Test SMS');

    expect($result->success)->toBeFalse();
    expect($result->providerMessageId)->toBeNull();
    expect($result->errorCode)->toBe('HTTP_402');
    expect($result->errorMessage)->toContain('402');
});

test('HttpSmsGateway handles network connection failures gracefully', function (): void {
    Http::fake([
        'https://api.sms-gateway.test/v1/messages' => function (): void {
            throw new ConnectionException('Connection timed out after 15000ms');
        },
    ]);

    $gateway = new HttpSmsGateway(
        endpoint: 'https://api.sms-gateway.test/v1/messages',
        apiKey: 'test-key',
    );

    $result = $gateway->sendSms('+254712345678', 'Test SMS');

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('GATEWAY_CONNECTION_ERROR');
    expect($result->errorMessage)->toContain('Connection timed out');
});

test('NotificationsServiceProvider resolves HttpSmsGateway when configured', function (): void {
    config()->set('notifications.sms_driver', 'http');

    $gateway = app(SmsGatewayPort::class);

    expect($gateway)->toBeInstanceOf(HttpSmsGateway::class);
});
