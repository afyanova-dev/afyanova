<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Presentation\Http\Controller;

use App\Domains\Authentication\Application\AdminBootstrapCompletionService;
use App\Domains\Authentication\Application\Fortify\PasswordValidationRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AdminBootstrapController
{
    use PasswordValidationRules;

    public function show(): Response
    {
        return Inertia::render('Auth/AdminBootstrap');
    }

    public function store(
        Request $request,
        AdminBootstrapCompletionService $bootstrap,
    ): RedirectResponse {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'password' => $this->passwordRules(),
        ]);

        $accessAwaitingApproval = $bootstrap->complete(
            hash('sha256', $validated['token']),
            $validated['password'],
        );
        $status = $accessAwaitingApproval
            ? 'Account setup is complete. The administrator who invited you must approve your access before you can sign in.'
            : 'Account setup is complete. Sign in using your new password.';

        return redirect()->route('login')->with('status', $status);
    }
}
