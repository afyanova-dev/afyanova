<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Presentation\Http\Controller;

use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ReceptionStartEncounterController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $encounter,
    ): RedirectResponse|JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        if (! Str::isUuid($encounter)) {
            abort(404);
        }

        try {
            $commandBus->dispatch(new StartEncounter(
                encounterId: $encounter,
                actorId: (string) $user->getAuthIdentifier(),
            ));
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()],
                ], $e->httpStatus());
            }

            return back()->withErrors(['start' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'data' => ['encounter_id' => $encounter, 'status' => 'ACTIVE'],
            ]);
        }

        if ($request->filled('return_to')) {
            $returnTo = (string) $request->input('return_to');
            if (str_starts_with($returnTo, '/') && ! str_starts_with($returnTo, '//')) {
                return redirect($returnTo)->with('status', 'Visit started successfully.');
            }
        }

        return back()->with('status', 'Visit started successfully.');
    }
}
