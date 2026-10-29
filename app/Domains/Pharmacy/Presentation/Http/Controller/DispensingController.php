<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\Controller;

use App\Domains\Pharmacy\Application\Command\Dispense;
use App\Domains\Pharmacy\Application\Command\MarkPickupNotCollected;
use App\Domains\Pharmacy\Application\Command\CancelPickup;
use App\Domains\Pharmacy\Application\Command\RecordPickup;
use App\Domains\Pharmacy\Application\Command\ReverseDispensing;
use App\Domains\Pharmacy\Presentation\Http\Request\DispenseRequest;
use App\Domains\Pharmacy\Presentation\Http\Request\RecordPickupRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DispensingController
{
    public function store(
        DispenseRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $dispensingId = (string) Str::uuid7();
        try {
            $commandBus->dispatch(new Dispense(
                dispensingId: $dispensingId,
                actorId: (string) $user->getAuthIdentifier(),
                pharmacyOrderId: (string) $request->input('pharmacy_order_id'),
                itemId: (string) $request->input('item_id'),
                locationId: (string) $request->input('location_id'),
                quantity: (string) $request->input('quantity'),
                batchNumber: $request->filled('batch_number') ? trim((string) $request->input('batch_number')) : null,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['dispense' => 'Not authorized to dispense.']);
        } catch (DomainException $e) {
            return back()->withErrors(['dispense' => $e->getMessage()]);
        }

        return back()->with('status', 'Medication dispensed. Stock consumed; charge captured where mapped.');
    }

    public function pickup(
        RecordPickupRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        try {
            $commandBus->dispatch(new RecordPickup(
                actorId: (string) $user->getAuthIdentifier(),
                pharmacyOrderId: (string) $request->input('pharmacy_order_id'),
                dispensingId: (string) $request->input('dispensing_id'),
                recipientType: (string) $request->input('recipient_type'),
                recipientName: $request->filled('recipient_name') ? trim((string) $request->input('recipient_name')) : null,
                verificationNote: $request->filled('verification_note') ? trim((string) $request->input('verification_note')) : null,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['dispense' => 'Not authorized to record pickups.']);
        } catch (DomainException $e) {
            return back()->withErrors(['dispense' => $e->getMessage()]);
        }

        return back()->with('status', 'Pickup recorded.');
    }

    public function markNotCollected(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('pharmacy.pickup.record'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $validated = $request->validate([
            'pharmacy_order_id' => ['required', 'uuid'],
            'dispensing_id' => ['required', 'uuid'],
            'reason_code' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $commandBus->dispatch(new MarkPickupNotCollected(
                actorId: (string) $user->getAuthIdentifier(),
                pharmacyOrderId: $validated['pharmacy_order_id'],
                dispensingId: $validated['dispensing_id'],
                reasonCode: $validated['reason_code'],
                note: $validated['note'] ?? null,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['dispense' => 'Not authorized to record pickups.']);
        } catch (DomainException | InvalidArgumentException $e) {
            return back()->withErrors(['dispense' => $e->getMessage()]);
        }

        return back()->with('status', 'Marked not collected with a coded reason.');
    }

    public function cancelPickup(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('pharmacy.pickup.record'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $validated = $request->validate([
            'pharmacy_order_id' => ['required', 'uuid'],
            'dispensing_id' => ['required', 'uuid'],
            'reason_code' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $commandBus->dispatch(new CancelPickup(
                actorId: (string) $user->getAuthIdentifier(),
                pharmacyOrderId: $validated['pharmacy_order_id'],
                dispensingId: $validated['dispensing_id'],
                reasonCode: $validated['reason_code'],
                note: $validated['note'] ?? null,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['dispense' => 'Not authorized to record pickups.']);
        } catch (DomainException | InvalidArgumentException $e) {
            return back()->withErrors(['dispense' => $e->getMessage()]);
        }

        return back()->with('status', 'Pickup cancelled with a coded reason.');
    }

    public function reverse(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $dispensingId,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('pharmacy.dispensing.reverse'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        if (! Str::isUuid($dispensingId)) {
            return back()->withErrors(['dispense' => 'Unknown dispensing.']);
        }

        $validated = $request->validate([
            'pharmacy_order_id' => ['required', 'uuid'],
            'reason_code' => ['required', 'string', 'in:DISPENSING_ERROR,WRONG_ITEM,WRONG_DOSE,WRONG_PATIENT,NOT_COLLECTED,OTHER'],
            'reason_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $commandBus->dispatch(new ReverseDispensing(
                reversalId: (string) Str::uuid7(),
                actorId: (string) $user->getAuthIdentifier(),
                pharmacyOrderId: $validated['pharmacy_order_id'],
                dispensingId: $dispensingId,
                reasonCode: $validated['reason_code'],
                reasonNote: $validated['reason_note'] ?? null,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['dispense' => 'Not authorized to reverse a dispensing.']);
        } catch (DomainException $e) {
            return back()->withErrors(['dispense' => $e->getMessage()]);
        }

        return back()->with('status', 'Dispensing reversed in full. Stock restored; charge reversed where posted.');
    }
}
