<?php

declare(strict_types=1);

namespace App\Shared\Bus\Middleware;

use App\Shared\Idempotency\IdempotencyConflict;
use App\Shared\Idempotency\IdempotencyStore;
use App\Shared\Tenancy\TenantContext;
use Closure;

/**
 * Idempotency claim middleware (IMPLEMENTATION-CONTRACTS.md §5 step 3 & 7, §14; AGENTS.md §25).
 */
final class ClaimIdempotencyKey
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(object $command, Closure $next): mixed
    {
        $key = property_exists($command, 'idempotencyKey') ? $command->idempotencyKey : null;

        if ($key === null || trim((string) $key) === '') {
            return $next($command);
        }

        $tenantId = $this->tenantContext->tenantId();
        $scope = get_class($command);

        $claim = $this->store->claim($tenantId, $scope, (string) $key);

        if ($claim->isCompleted()) {
            return $claim->storedResult;
        }

        if ($claim->isInProgress()) {
            throw IdempotencyConflict::keyInProgress($scope, (string) $key);
        }

        $result = $next($command);

        $this->store->complete($tenantId, $scope, (string) $key, $result);

        return $result;
    }
}
