<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Secret;

use App\Platform\Integration\Contracts\Port\SecretResolverPort;

final class ConfigSecretResolver implements SecretResolverPort
{
    /** @var array<string, string> */
    private array $inMemorySecrets = [];

    public function resolve(string $secretRef): ?string
    {
        if (isset($this->inMemorySecrets[$secretRef])) {
            return $this->inMemorySecrets[$secretRef];
        }

        /** @var string|null $configValue */
        $configValue = config('integration.secrets.'.$secretRef);

        return $configValue;
    }

    public function setSecret(string $secretRef, string $secretValue): void
    {
        $this->inMemorySecrets[$secretRef] = $secretValue;
    }
}
