<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Port;

use App\Platform\Integration\Domain\Model\OutboundMessage;

interface IntegrationTransportClient
{
    /**
     * @param  array<string, string>  $headers
     * @return array{success: bool, provider_ref: ?string, response: ?array<string, mixed>, error: ?string}
     */
    public function send(OutboundMessage $message, string $targetUrl, array $headers = []): array;
}
