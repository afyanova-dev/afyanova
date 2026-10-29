<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the request's tenant + facility security context
 * (IMPLEMENTATION-CONTRACTS.md §11.1, §11.3;
 * docs/00-foundation/05-tenant-isolation.md §7–§11;
 * docs/00-foundation/03-identity-rbac.md §56;
 * docs/00-foundation/04-authentication.md §31–§32).
 *
 * Runs on the `web` group AFTER `auth`. For an unauthenticated request it is a
 * no-op (the login screen needs no tenant). For an authenticated request it:
 *   - takes the tenant from the principal (`User::$tenant_id`) — never the client;
 *   - opens ONE {@see Tenancy::run} transaction for the whole request;
 *   - inside it, resolves General, the persisted selection, or — for a
 *     facility-only user — the sole current assignment, validating facility
 *     selection against active assignments; stale or cross-tenant facility
 *     ids are rejected, never used to widen scope;
 *   - binds a fresh, immutable {@see TenantContext} for this request.
 *
 * The middleware is the ONLY sanctioned establisher: it replaces the container
 * binding each request, so downstream code that calls `establish()` on the
 * already-resolved instance still fails (docs 05 §9).
 */
final class ResolveTenantContext
{
    public function __construct(
        private readonly Application $app,
        private readonly ScopeChecker $scope,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if ($route instanceof Route) {
            // Route instances may outlive a request under tests or long-lived workers.
            $route->flushController();
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $context = new TenantContext;
        $this->app->instance(TenantContext::class, $context);

        if ($user->tenant_id === null) {
            abort_unless($user->isPlatformSuperAdmin(), 403, 'The account has no tenant context.');

            return $next($request);
        }

        $tenantId = (string) $user->tenant_id;

        if ($tenantId === '') {
            abort(403, 'The account has no tenant.');
        }

        $tenantStatus = DB::table('shared_tenants')->where('id', $tenantId)->value('status');
        if ($tenantStatus !== 'ACTIVE') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'This tenant is not active. Contact your platform administrator.',
            ]);
        }

        return Tenancy::run($tenantId, function () use ($request, $next, $user, $tenantId, $context): Response {
            [$facilityId, $general] = $this->resolveContext($request, $user);
            $context->establish($tenantId, $facilityId, $general);

            return $next($request);
        });
    }

    /**
     * Resolution rules:
     * - a valid persisted selection wins;
     * - a stale, revoked, or spoofed selection is dropped, never widening scope;
     * - General users default to General (no auto facility pick);
     * - a facility-only user with EXACTLY ONE current assignment is entered
     *   into that facility automatically (no manual switch required);
     * - otherwise (zero or multiple facilities, no choice yet) the context
     *   resolves unresolved.
     *
     * @return array{?string, bool}
     */
    private function resolveContext(Request $request, User $user): array
    {
        $allowed = $this->scope->selectableFacilities($user);
        $selected = $request->session()->get('active_facility_id');
        $mode = $request->session()->get('facility_context_mode');

        if ($selected !== null) {
            if (in_array((string) $selected, $allowed, true)) {
                $request->session()->put('facility_context_mode', 'facility');

                return [(string) $selected, false];
            }

            // Stale, revoked, or spoofed — drop it, do not widen scope.
            $request->session()->forget('active_facility_id');
            $request->session()->forget('facility_context_mode');

            return $this->defaultContext($request, $user, $allowed);
        }

        if ($mode === 'facility') {
            // An active-facility selection was explicitly cleared or lost;
            // this is NOT General and NOT an implicit facility pick.
            $request->session()->forget('facility_context_mode');
        }

        return $this->defaultContext($request, $user, $allowed);
    }

    /**
     * Default when nothing valid is selected: General for general-scope users,
     * the sole assigned facility for facility-only users, unresolved otherwise.
     *
     * @param  list<string>  $allowed
     * @return array{?string, bool}
     */
    private function defaultContext(Request $request, User $user, array $allowed): array
    {
        if ($this->scope->canUseGeneralContext($user)) {
            $request->session()->put('facility_context_mode', 'general');

            return [null, true];
        }

        if (count($allowed) === 1) {
            $facilityId = (string) $allowed[0];
            $request->session()->put('active_facility_id', $facilityId);
            $request->session()->put('facility_context_mode', 'facility');

            return [$facilityId, false];
        }

        return [null, false];
    }
}
