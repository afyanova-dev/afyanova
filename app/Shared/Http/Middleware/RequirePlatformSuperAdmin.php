<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class RequirePlatformSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isPlatformSuperAdmin(), 403);
        $accessVersion = DB::table('platform_super_admins')
            ->where('user_id', $user->getKey())
            ->where('status', 'ACTIVE')
            ->value('access_version');
        if ($accessVersion === null || $request->session()->get('platform_access_version') !== (int) $accessVersion) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Your Platform Super Admin access changed. Sign in again to continue.',
            ]);
        }
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('platform-admin.security.two-factor.setup');
        }

        return $next($request);
    }
}
