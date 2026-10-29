<?php

declare(strict_types=1);

namespace App\Domains\Patient\Presentation\Http\Controller;

use App\Domains\Patient\Application\Command\MarkPatientDeceased;
use App\Domains\Patient\Domain\Exception\IllegalPatientStatusTransition;
use App\Domains\Patient\Domain\Exception\MergedPatientIsImmutable;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Record a patient identity as deceased (OpenMRS "Mark patient deceased"
 * action). Terminal transition {ACTIVE,INACTIVE} -> DECEASED (ADR-116).
 */
final class MarkPatientDeceasedController
{
    public function __invoke(
        string $patientId,
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        abort_unless($user->can('patient.patients.mark_deceased'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : null;
        $reason = $reason === '' ? null : $reason;

        try {
            $commandBus->dispatch(new MarkPatientDeceased(
                patientId: $patientId,
                actorId: (string) $user->id,
                reason: $reason,
            ));
        } catch (NotFound) {
            abort(404, 'Patient not found.');
        } catch (IllegalPatientStatusTransition $e) {
            return redirect()->back()->withErrors([
                'mark_deceased' => $e->getMessage(),
            ]);
        } catch (MergedPatientIsImmutable $e) {
            return redirect()->back()->withErrors([
                'mark_deceased' => $e->getMessage(),
            ]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'mark_deceased' => [$e->getMessage()],
            ]);
        }

        return redirect()->back()->with('success', 'Patient marked as deceased.');
    }
}
