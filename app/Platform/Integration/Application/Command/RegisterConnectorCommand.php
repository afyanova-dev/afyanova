<?php

declare(strict_types=1);

namespace App\Platform\Integration\Application\Command;

use App\Platform\Integration\Domain\Enum\AuthType;
use App\Platform\Integration\Domain\Enum\ConnectorDirection;
use App\Platform\Integration\Domain\Enum\ConnectorFamily;
use App\Platform\Integration\Domain\Enum\PayloadSensitivity;

final readonly class RegisterConnectorCommand
{
    /**
     * @param  array<string, mixed>|null  $config
     */
    public function __construct(
        public string $tenantId,
        public string $name,
        public string $key,
        public ConnectorFamily $family,
        public ConnectorDirection $direction,
        public ?string $baseUrl = null,
        public AuthType $authType = AuthType::HMAC_SHA256,
        public PayloadSensitivity $payloadSensitivity = PayloadSensitivity::STANDARD,
        public ?array $config = null,
    ) {}
}
