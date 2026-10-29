<?php

declare(strict_types=1);

use App\Domains\Authentication\Presentation\Http\Middleware\EnsureAbsoluteSessionLifetime;
use App\Domains\Authentication\Presentation\Http\Middleware\EnsureOperationalStaffAccess;
use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Domain\DomainException;
use App\Shared\Http\Middleware\AssignCorrelationId;
use App\Shared\Http\Middleware\HandleInertiaRequests;
use App\Shared\Support\CorrelationId;
use App\Shared\Tenancy\ResolveTenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

if (! class_exists(Application::class)) {
    require_once dirname(__DIR__).'/vendor/autoload.php';
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // IC §7.2 — drain the transactional outbox to its consumers.
        $schedule->command('outbox:relay --once')->everyMinute()->withoutOverlapping();

        // ADR-149 D-C — expire bed reservations nothing ever touched again to
        // trigger the self-healing check (coarser cadence than the relay).
        $schedule->command('bw:reservations:expire --once')->hourly()->withoutOverlapping();

        // ADR-179 D-F — flip ACTIVE purchase contracts past their expiry_date to
        // EXPIRED (status-accuracy only; Inv 32 stays check-at-use).
        $schedule->command('procurement:expire-contracts --once')->hourly()->withoutOverlapping();

        // ADR-180 D-H — release stock reservations whose hold_until has lapsed
        // (Inv 18; `reserved_qty` / `available` accuracy).
        $schedule->command('inventory:expire-reservations --once')->hourly()->withoutOverlapping();

        // ADR-202 D-B — flip APPROVED / PARTIALLY_APPROVED pre-authorizations
        // past their valid_to to EXPIRED (status accuracy; §276 Inv 25 stays check-at-use).
        $schedule->command('insurance:expire-authorizations --once')->hourly()->withoutOverlapping();

        // ADR-203 — flip ACTIVE payer contracts past their effective_to to EXPIRED
        // (status accuracy; §275 Inv 21 stays check-at-use).
        $schedule->command('insurance:expire-contracts --once')->hourly()->withoutOverlapping();

        $schedule->command('patient:registration-drafts:purge')->daily()->withoutOverlapping();

        // HR P0 — invitations expire as a lifecycle transition, not only when
        // someone opens the worklist. Every five minutes keeps the 60-minute
        // TTL accurate without a noisy per-minute sweep.
        $schedule->command('identity:expire-facility-invitations --once')->everyFiveMinutes()->withoutOverlapping(10);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // IC §19 — correlation id first, on every surface.
        $middleware->prepend(AssignCorrelationId::class);

        // IC §11 — establish the tenant/facility security context (from the
        // authenticated principal only) and wrap the request in one
        // Tenancy::run transaction. Before Inertia so shared props see context.
        // IC §16 — Inertia shared props on the web surface.
        $middleware->web(append: [
            ResolveTenantContext::class,
            EnsureAbsoluteSessionLifetime::class,
            EnsureOperationalStaffAccess::class,
            HandleInertiaRequests::class,
        ]);

        // IC §15 — machine API is stateless + its own tenant/auth guard (added PHASE 12+).
        $middleware->api(prepend: []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // IC §15 / §19 — the error taxonomy → HTTP map. Never 200 for a failure.
        $exceptions->render(function (ConcurrencyConflict $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()]], 409)
                : back()->with('error', $e->getMessage());
        });

        $exceptions->render(function (DomainException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()]], $e->httpStatus())
                : back()->with('error', $e->getMessage());
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['error' => ['code' => 'not_found', 'message' => 'Not found.']], 404)
                : null;
        });

        // Attach correlation id to every reported/rendered error.
        $exceptions->context(fn () => ['correlation_id' => CorrelationId::get()]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->create();
