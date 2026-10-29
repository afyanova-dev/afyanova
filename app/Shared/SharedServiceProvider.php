<?php

declare(strict_types=1);

namespace App\Shared;

use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Capability\FacilityCapabilities;
use App\Shared\Console\ConformanceCommand;
use App\Shared\Events\OutboxRelay;
use App\Shared\Events\OutboxWriter;
use App\Shared\Idempotency\IdempotencyStore;
use App\Shared\Jobs\DeadLetterHandler;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * The shared kernel provider (IMPLEMENTATION-CONTRACTS.md §2.2, §2.4).
 * Registered first in bootstrap/providers.php.
 *
 * PHASE 0–1: console tooling + baseline HTTP/observability wiring.
 * PHASE 2  : Tenancy (TenantContext binding; ResolveTenantContext is appended to
 *            the web group in bootstrap/app.php) + the authorization Gate.
 * PHASE 4  : CommandBus / QueryBus / OutboxWriter / OutboxRelay / EventRegistry
 *            / AuditLogger command lifecycle / IdempotencyStore.
 */
final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One immutable tenant/facility context per request (IC §11.1).
        $this->app->scoped(TenantContext::class);

        // One capability-read cache per request (docs 06 §119).
        $this->app->scoped(FacilityCapabilities::class);

        // PHASE 4: Bus, Outbox, Idempotency, and DeadLetter bindings (IC §2.2, §5, §6, §7, §14)
        $this->app->singleton(CommandBus::class);
        $this->app->singleton(QueryBus::class);
        $this->app->singleton(IdempotencyStore::class);
        $this->app->singleton(OutboxWriter::class);
        $this->app->singleton(DeadLetterHandler::class);
        $this->app->singleton(AuditLogger::class);
    }

    public function boot(): void
    {
        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');
        $this->registerAuthorizationGate();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ConformanceCommand::class,
                OutboxRelay::class,
            ]);
        }
    }

    /**
     * Permission keys resolve through a single Gate::before hook
     * (IMPLEMENTATION-CONTRACTS.md §12; docs/00-foundation/03-identity-rbac.md
     * §27–§28, §55 default-deny).
     *
     * - a registered `{domain}.{resource}.{action}` key → true iff the user
     *   holds it in the current facility context, else null (→ default deny);
     * - anything else (a policy ability) → null, so Laravel policies still run.
     *
     * Returning null (never false) here means this hook never overrides a
     * policy decision.
     */
    private function registerAuthorizationGate(): void
    {
        Gate::before(function ($user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            if (! PermissionRegistry::has($ability)) {
                return null;
            }

            return $user->hasPermissionKey($ability) ? true : null;
        });
    }
}
