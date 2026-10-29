<?php

declare(strict_types=1);

namespace App\Platform\Integration\Contracts\Port;

interface DomainDispatcherPort
{
    /**
     * Dispatches translated command to authoritative domain boundary.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: string, reason: ?string}
     */
    public function dispatch(string $commandType, array $payload, string $tenantId): array;
}
