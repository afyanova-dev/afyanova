<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Adapter;

use App\Platform\Notifications\Domain\Port\GatewayResultDto;
use App\Platform\Notifications\Domain\Port\PushGatewayPort;
use Illuminate\Support\Str;

final class SyncPushGateway implements PushGatewayPort
{
    /** @var list<array{deviceToken: string, title: string, body: string, payload: array<string, mixed>}> */
    public static array $dispatched = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendPush(string $deviceToken, string $title, string $body, array $payload = []): GatewayResultDto
    {
        self::$dispatched[] = [
            'deviceToken' => $deviceToken,
            'title' => $title,
            'body' => $body,
            'payload' => $payload,
        ];

        return GatewayResultDto::ok('PUSH-'.Str::upper(Str::random(10)));
    }

    public static function reset(): void
    {
        self::$dispatched = [];
    }
}
