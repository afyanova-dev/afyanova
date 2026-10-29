<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Port;

interface SecretResolverPort
{
    public function resolve(string $secretRef): ?string;
}
