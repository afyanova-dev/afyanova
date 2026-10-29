<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Repository;

use App\Platform\Notifications\Domain\Model\DeduplicationKey;
use App\Platform\Notifications\Domain\Repository\DeduplicationRepositoryInterface;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final class EloquentDeduplicationRepository implements DeduplicationRepositoryInterface
{
    public function __construct(private readonly Container $container) {}

    public function acquireLock(string $dedupeKey, string $requestId, int $ttlSeconds = 86400): bool
    {
        /** @var TenantContext $tenantContext */
        $tenantContext = $this->container->make(TenantContext::class);
        $tenantId = $tenantContext->tenantId();
        $now = CarbonImmutable::now();
        $expiresAt = $now->addSeconds($ttlSeconds);

        // Remove expired entry if present
        DeduplicationKey::query()
            ->where('tenant_id', $tenantId)
            ->where('dedupe_key', $dedupeKey)
            ->where('expires_at', '<=', $now)
            ->delete();

        try {
            DeduplicationKey::query()->create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'dedupe_key' => $dedupeKey,
                'request_id' => $requestId,
                'expires_at' => $expiresAt,
            ]);

            return true;
        } catch (QueryException $e) {
            // PostgreSQL unique violation SQLSTATE 23505
            return false;
        }
    }

    public function isDuplicate(string $dedupeKey): bool
    {
        /** @var TenantContext $tenantContext */
        $tenantContext = $this->container->make(TenantContext::class);
        $tenantId = $tenantContext->tenantId();
        $now = CarbonImmutable::now();

        return DeduplicationKey::query()
            ->where('tenant_id', $tenantId)
            ->where('dedupe_key', $dedupeKey)
            ->where('expires_at', '>', $now)
            ->exists();
    }
}
