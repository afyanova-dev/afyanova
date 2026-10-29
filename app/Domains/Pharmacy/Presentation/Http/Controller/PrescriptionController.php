<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\Controller;

use App\Domains\Pharmacy\Application\Command\AcceptPrescription;
use App\Domains\Pharmacy\Application\Command\CancelPrescription;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PrescriptionController
{
    /**
     * Accept an electronic prescription into the pharmacy (RECEIVED → ACTIVE;
     * 16-pharmacy-domain.md §19, §24; ADR-181 D-E). The handler fetches the
     * prescribing decision through the PROTECTED `ClinicalApi` under the acting
     * pharmacist's own authorization (`clinical.prescribing.read` scoped to the
     * originating facility) and writes the immutable clinical snapshot; this
     * route carries no clinical payload. Paper is accepted through
     * `TranscriptionController` (typed at transcribe time).
     */
    public function accept(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $prescriptionId,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('pharmacy.prescriptions.accept'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        if (! Str::isUuid($prescriptionId)) {
            return back()->withErrors(['prescription' => 'Unknown prescription.']);
        }

        try {
            $commandBus->dispatch(new AcceptPrescription(
                prescriptionId: $prescriptionId,
                actorId: (string) $user->getAuthIdentifier(),
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['prescription' => 'Not authorized to accept prescriptions.']);
        } catch (DomainException | InvalidArgumentException $e) {
            return back()->withErrors(['prescription' => $e->getMessage()]);
        }

        return back()->with('status', 'Prescription accepted.');
    }

    public function cancel(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $prescriptionId,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('pharmacy.prescriptions.accept'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        if (! Str::isUuid($prescriptionId)) {
            return back()->withErrors(['prescription' => 'Unknown prescription.']);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $commandBus->dispatch(new CancelPrescription(
                prescriptionId: $prescriptionId,
                actorId: (string) $user->getAuthIdentifier(),
                reason: $validated['reason'],
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['prescription' => 'Not authorized to cancel prescriptions.']);
        } catch (DomainException | InvalidArgumentException $e) {
            return back()->withErrors(['prescription' => $e->getMessage()]);
        }

        return back()->with('status', 'Prescription cancelled.');
    }
}
