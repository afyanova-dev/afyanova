<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

final readonly class ConfigureWebhookEndpointCommand
{
    /**
     * @param  list<string>|null  $allowedIps
     */
    public function __construct(
        public string $connectorId,
        public string $pathSlug,
        public ?string $secretRef = null,
        public ?array $allowedIps = null,
        public bool $active = true,
    ) {}
}
