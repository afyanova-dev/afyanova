<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Presentation\Http\Controller;

use App\Domains\Authentication\Application\Fortify\PasswordValidationRules;
use App\Domains\Authentication\Application\PlatformSuperAdminBootstrapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformSuperAdminBootstrapController
{
    use PasswordValidationRules;

    public function show(): Response
    {
        return Inertia::render('Auth/PlatformSuperAdminBootstrap');
    }

    public function store(Request $request, PlatformSuperAdminBootstrapService $bootstrap): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'password' => $this->passwordRules(),
        ]);
        $tokenHash = hash('sha256', $validated['token']);

        $bootstrap->complete($tokenHash, $validated['password']);

        return redirect()->route('login')->with('status', 'Account setup is complete. Sign in and enroll two-factor authentication to access platform administration.');
    }
}
