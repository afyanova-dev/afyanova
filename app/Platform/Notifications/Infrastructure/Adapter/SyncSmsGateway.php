<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Adapter;

use App\Platform\Notifications\Domain\Port\GatewayResultDto;
use App\Platform\Notifications\Domain\Port\SmsGatewayPort;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SyncSmsGateway implements SmsGatewayPort
{
    /** @var list<array{toPhone: string, content: string, senderId: string|null}> */
    public static array $dispatched = [];

    public function sendSms(string $toPhone, string $content, ?string $senderId = null): GatewayResultDto
    {
        self::$dispatched[] = [
            'toPhone' => $toPhone,
            'content' => $content,
            'senderId' => $senderId,
        ];

        Log::info("SMS Dispatched: To [{$toPhone}], From [{$senderId}], Length [".strlen($content).']');

        return GatewayResultDto::ok('SMS-'.Str::upper(Str::random(10)));
    }

    public static function reset(): void
    {
        self::$dispatched = [];
    }
}
