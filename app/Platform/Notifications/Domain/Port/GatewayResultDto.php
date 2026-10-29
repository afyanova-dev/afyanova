<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Port;

final readonly class GatewayResultDto
{
    public function __construct(
        public bool $success,
        public ?string $providerMessageId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    public static function ok(?string $providerMessageId = null): self
    {
        return new self(true, $providerMessageId);
    }

    public static function fail(string $errorCode, string $errorMessage): self
    {
        return new self(false, null, $errorCode, $errorMessage);
    }
}
