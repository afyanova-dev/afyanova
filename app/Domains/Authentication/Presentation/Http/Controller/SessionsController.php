<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Presentation\Http\Controller;

use App\Domains\Authentication\Infrastructure\Sessions;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My sessions / devices" (docs/00-foundation/04-authentication.md §22–§23).
 * HTTP orchestration only — {@see Sessions} owns the data access.
 */
final class SessionsController
{
    public function __construct(private readonly Sessions $sessions) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('Auth/Sessions', [
            'sessions' => $this->sessions->activeFor((string) $user->getKey(), $request->session()->getId()),
        ]);
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $this->sessions->revoke((string) $user->getKey(), $id);

        return back()->with('status', __('Session revoked.'));
    }
}
