<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Presentation\Http\Middleware;

use App\Domains\Authentication\Support\AuthEventRecorder;
use App\Domains\Identity\Contracts\LinkedStaffAccessGate;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Absolute session lifetime (docs/00-foundation/04-authentication.md §20).
 *
 * Laravel's `SESSION_LIFETIME` is an *idle* timeout — it slides on every
 * request. This caps the total age of an authenticated session regardless of
 * activity; past the cap the user is logged out and must re-authenticate.
 */
final class EnsureAbsoluteSessionLifetime
{
    /** Absolute cap, in minutes (12 hours). */
    private const MAX_MINUTES = 720;

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && $request->hasSession()) {
            $user = $request->user();
            if ($user instanceof User && $user->tenant_id !== null) {
                if (app(LinkedStaffAccessGate::class)->isBlockedByHrLifecycle((string) $user->tenant_id, (string) $user->getKey())) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()->route('login')->withErrors([
                        'email' => 'Your account access is blocked by an HR lifecycle status. Please contact your administrator.',
                    ]);
                }

                $session = DB::table('aut_sessions')
                    ->where('tenant_id', $user->tenant_id)
                    ->where('user_id', $user->getKey())
                    ->where('session_id', $request->session()->getId())
                    ->first(['revoked_at']);

                $trackedSessionWasRevoked = $session !== null && $session->revoked_at !== null;
                $expectedSessionIsMissing = $session === null
                    && $request->session()->get('aut_session_tracked') === true;
                if ($trackedSessionWasRevoked || $expectedSessionIsMissing) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()->route('login')->withErrors([
                        'email' => 'Your session has been revoked. Please sign in again.',
                    ]);
                }

                if ($session !== null) {
                    DB::table('aut_sessions')
                        ->where('tenant_id', $user->tenant_id)
                        ->where('user_id', $user->getKey())
                        ->where('session_id', $request->session()->getId())
                        ->whereNull('revoked_at')
                        ->update(['last_active_at' => now(), 'updated_at' => now()]);
                }
            }

            $startedAt = $request->session()->get('authenticated_at');

            if ($startedAt === null) {
                $request->session()->put('authenticated_at', now()->getTimestamp());
            } elseif (now()->getTimestamp() - (int) $startedAt > self::MAX_MINUTES * 60) {
                $user = $request->user();
                if ($user instanceof User) {
                    app(AuthEventRecorder::class)->record('session_expired_absolute', $user);
                }

                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', __('auth.session_expired'));
            }
        }

        $response = $next($request);

        $user = $request->user();
        if (
            Auth::check()
            && $user instanceof User
            && $request->hasSession()
            && $request->session()->get('aut_session_tracking_pending') === true
        ) {
            app(AuthEventRecorder::class)->trackCurrentSession($user, $request);
        }

        return $response;
    }
}
