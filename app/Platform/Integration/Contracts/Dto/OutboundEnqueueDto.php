<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Dto;

final readonly class OutboundEnqueueDto
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $tenantId,
        public string $connectorId,
        public string $triggeredBy,
        public string $correlationId,
        public array $payload,
        public ?string $causationId = null,
        public ?string $recipientRef = null,
        public int $maxAttempts = 3,
    ) {}
}
