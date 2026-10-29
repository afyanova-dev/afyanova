<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Closure;
use Illuminate\Container\Container;

/**
 * The non-HTTP counterpart of {@see ResolveTenantContext}
 * (IMPLEMENTATION-CONTRACTS.md §11.1, §14; docs/00-foundation/05-tenant-isolation.md §9).
 *
 * HTTP requests get their {@see TenantContext} from the middleware, resolved
 * from the authenticated principal. Seeders, `artisan` console commands and
 * queued jobs have no principal and no middleware, but still need the same
 * two-layer context so that `BelongsToTenant` models and the DB GUC agree:
 *
 *   - binds a fresh, immutable {@see TenantContext} for the callback,
 *   - opens the one {@see Tenancy::run} transaction (sets `app.tenant_id`),
 *   - establishes the context inside it,
 *   - restores the previous binding afterwards (so a seeder can walk many
 *     tenants, one `run()` call each).
 *
 * The tenant id here comes from trusted server-side state only — a seeded
 * fixture, a `--tenant` option an operator typed, a job's captured
 * `tenantId` — never from client input. Cross-tenant / privileged work still
 * goes through {@see SystemContext}, not this.
 */
final class TenantContextScope
{
    /**
     * @template T
     *
     * @param  Closure(TenantContext): T  $fn
     * @return T
     */
    public static function run(string $tenantId, ?string $facilityId, Closure $fn): mixed
    {
        $container = Container::getInstance();
        $previous = $container->bound(TenantContext::class)
            ? $container->make(TenantContext::class)
            : null;

        $context = new TenantContext;
        $container->instance(TenantContext::class, $context);

        try {
            return Tenancy::run($tenantId, static function () use ($context, $tenantId, $facilityId, $fn): mixed {
                $context->establish($tenantId, $facilityId, general: $facilityId === null);

                return $fn($context);
            });
        } finally {
            if ($previous instanceof TenantContext) {
                $container->instance(TenantContext::class, $previous);
            } else {
                $container->forgetInstance(TenantContext::class);
            }
        }
    }
}
