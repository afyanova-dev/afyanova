<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Presentation\Http\Middleware;

use App\Domains\Authentication\Application\PendingAdministrativeAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RequireApprovedAdministrativeAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $message = app(PendingAdministrativeAccess::class)->messageFor($user);
        if ($message === null) {
            return $next($request);
        }

        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
