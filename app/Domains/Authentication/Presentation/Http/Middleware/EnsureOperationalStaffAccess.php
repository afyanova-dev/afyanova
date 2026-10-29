<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Presentation\Http\Middleware;

use App\Domains\Identity\Contracts\LinkedStaffAccessGate;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps linked staff who are still pending onboarding out of operational
 * workspaces while leaving sign-in, account setup, password, 2FA, session
 * management, and sign-out available.
 *
 * The check is path-based so it also covers workspace and patient-chart routes
 * that do not yet exist when this middleware is registered. The gate is
 * intentionally fail-closed for all current and future operational routes.
 */
final class EnsureOperationalStaffAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->isOperationalPath($request)) {
            return $next($request);
        }

        if ($user->tenant_id !== null
            && app(LinkedStaffAccessGate::class)->isPendingOnboarding((string) $user->tenant_id, (string) $user->getKey())) {
            return redirect()->route('staff.onboarding-pending');
        }

        return $next($request);
    }

    private function isOperationalPath(Request $request): bool
    {
        $path = trim($request->path(), '/');

        // Account-security setup remains available while onboarding is pending.
        if ($path === 'platform-admin/security/two-factor') {
            return false;
        }

        return $path === 'dashboard'
            || $path === 'workspaces'
            || str_starts_with($path, 'workspaces/')
            || $path === 'patient'
            || str_starts_with($path, 'patient/')
            || $path === 'broadcasting'
            || str_starts_with($path, 'broadcasting/')
            || $path === 'platform-admin'
            || str_starts_with($path, 'platform-admin/');
    }
}
