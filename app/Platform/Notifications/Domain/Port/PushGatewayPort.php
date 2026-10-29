<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Port;

interface PushGatewayPort
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendPush(string $deviceToken, string $title, string $body, array $payload = []): GatewayResultDto;
}
