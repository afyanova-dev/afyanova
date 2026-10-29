<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Presentation\Http\Controller;

use App\Domains\Authentication\Application\PlatformSuperAdminAccessService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class PlatformSuperAdminAccessController
{
    public function index(Request $request, PlatformSuperAdminAccessService $access): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return Inertia::render('PlatformAdmin/SuperAdmins/Index', [
            ...$access->overview(),
            'currentAdminId' => (string) $user->getKey(),
        ]);
    }

    public function requestChange(Request $request, PlatformSuperAdminAccessService $access): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'target_id' => ['required', 'uuid'],
            'action' => ['required', Rule::in(['SUSPEND', 'REACTIVATE', 'RESET_MFA'])],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $access->requestChange(
                targetId: $validated['target_id'],
                action: $validated['action'],
                reason: trim($validated['reason']),
                requesterId: (string) $user->getKey(),
            );
        } catch (RuntimeException $exception) {
            Log::notice('Platform Super Admin access request was rejected.', [
                'actor_id' => (string) $user->getKey(),
                'target_id' => $validated['target_id'],
                'action' => $validated['action'],
                'reason' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['action' => $exception->getMessage()]);
        }

        return back()->with('status', 'Access request submitted for independent Platform Super Admin review.');
    }

    public function review(
        Request $request,
        string $requestId,
        PlatformSuperAdminAccessService $access,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'review_notes' => ['required', 'string', 'min:8', 'max:1000'],
        ]);

        try {
            $access->reviewRequest(
                requestId: $requestId,
                reviewerId: (string) $user->getKey(),
                approve: (bool) $validated['approve'],
                reviewNotes: trim($validated['review_notes']),
            );
        } catch (RuntimeException $exception) {
            Log::notice('Platform Super Admin access review was rejected.', [
                'reviewer_id' => (string) $user->getKey(),
                'request_id' => $requestId,
                'reason' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['review_notes' => $exception->getMessage()]);
        }

        return back()->with('status', $validated['approve']
            ? 'Platform Super Admin access request approved and audited.'
            : 'Platform Super Admin access request rejected and audited.');
    }
}
