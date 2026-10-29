<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Adapter;

use App\Platform\Notifications\Domain\Port\EmailGatewayPort;
use App\Platform\Notifications\Domain\Port\GatewayResultDto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SyncEmailGateway implements EmailGatewayPort
{
    /** @var list<array{toEmail: string, subject: string, bodyHtml: string}> */
    public static array $dispatched = [];

    public function sendEmail(string $toEmail, string $subject, string $bodyHtml): GatewayResultDto
    {
        self::$dispatched[] = [
            'toEmail' => $toEmail,
            'subject' => $subject,
            'bodyHtml' => $bodyHtml,
        ];

        Log::info("Email Dispatched: To [{$toEmail}], Subject [{$subject}]");

        return GatewayResultDto::ok('EMAIL-'.Str::upper(Str::random(10)));
    }

    public static function reset(): void
    {
        self::$dispatched = [];
    }
}
