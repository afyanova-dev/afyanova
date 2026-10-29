<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Port;

interface SmsGatewayPort
{
    public function sendSms(string $toPhone, string $content, ?string $senderId = null): GatewayResultDto;
}
