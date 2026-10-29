<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Models\User;
use App\Shared\Authorization\OrganizationPermissionScope;
use App\Shared\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireAdminWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        abort_unless(app(TenantContext::class)->hasTenant(), 403);
        abort_unless(
            $user->can('admin.workspace.access')
                || app(OrganizationPermissionScope::class)->allows($user, 'admin.workspace.access'),
            403,
        );
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('admin.security.two-factor.setup');
        }

        return $next($request);
    }
}
