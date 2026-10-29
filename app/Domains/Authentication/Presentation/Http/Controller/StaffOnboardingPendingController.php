<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Presentation\Http\Controller;

use App\Domains\Identity\Contracts\LinkedStaffAccessGate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class StaffOnboardingPendingController
{
    public function __invoke(Request $request, LinkedStaffAccessGate $access): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $staffStatus = $user->tenant_id === null
            ? null
            : $access->linkedStaffStatus((string) $user->tenant_id, (string) $user->getKey());

        if (! in_array($staffStatus, ['PROSPECT', 'ONBOARDING'], true)) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/StaffOnboardingPending', [
            'email' => $user->email,
            'staffStatus' => $staffStatus,
        ]);
    }
}
