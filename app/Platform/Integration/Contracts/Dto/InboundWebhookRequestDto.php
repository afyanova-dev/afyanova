<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Dto;

final readonly class InboundWebhookRequestDto
{
    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $parsedBody
     */
    public function __construct(
        public string $tenantId,
        public string $pathSlug,
        public array $headers,
        public string $rawBody,
        public array $parsedBody,
        public string $externalRef,
        public string $eventType,
        public string $correlationId,
        public ?string $causationId = null,
        public ?string $clientIp = null,
    ) {}
}
