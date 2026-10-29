<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\Controller;

use App\Domains\Pharmacy\Application\Command\CancelPharmacyOrder;
use App\Domains\Pharmacy\Application\Command\CreatePharmacyOrder;
use App\Domains\Pharmacy\Application\Command\HoldPharmacyOrder;
use App\Domains\Pharmacy\Application\Command\RejectPharmacyOrder;
use App\Domains\Pharmacy\Application\Command\ReleasePharmacyOrderHold;
use App\Domains\Pharmacy\Application\Command\ValidatePharmacyOrder;
use App\Domains\Pharmacy\Presentation\Http\Request\CreateOrderRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class OrderController
{
    public function store(
        CreateOrderRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $orderId = (string) Str::uuid7();
        try {
            $commandBus->dispatch(new CreatePharmacyOrder(
                pharmacyOrderId: $orderId,
                actorId: (string) $user->getAuthIdentifier(),
                prescriptionId: (string) $request->input('prescription_id'),
                priority: $request->filled('priority') ? (string) $request->input('priority') : 'ROUTINE',
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['order' => 'Not authorized to manage pharmacy orders.']);
        } catch (DomainException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return redirect()->route('pharmacy.index', ['order_id' => $orderId])
            ->with('status', 'Pharmacy order created. Validate it before dispensing.');
    }

    public function validate(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $pharmacyOrderId,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('pharmacy.orders.manage'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        if (! Str::isUuid($pharmacyOrderId)) {
            return back()->withErrors(['order' => 'Unknown pharmacy order.']);
        }

        try {
            $commandBus->dispatch(new ValidatePharmacyOrder(
                pharmacyOrderId: $pharmacyOrderId,
                actorId: (string) $user->getAuthIdentifier(),
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['order' => 'Not authorized to validate pharmacy orders.']);
        } catch (DomainException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('status', 'Order validated — ready to dispense.');
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function guard(Request $request, TenantContext $context, string $pharmacyOrderId, array $rules): array|string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('pharmacy.orders.manage'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        if (! Str::isUuid($pharmacyOrderId)) {
            return 'Unknown pharmacy order.';
        }

        return $request->validate($rules);
    }

    public function reject(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $pharmacyOrderId,
    ): RedirectResponse {
        $guarded = $this->guard($request, $context, $pharmacyOrderId, [
            'reason_code' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if (is_string($guarded)) {
            return back()->withErrors(['order' => $guarded]);
        }
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $commandBus->dispatch(new RejectPharmacyOrder(
                pharmacyOrderId: $pharmacyOrderId,
                actorId: (string) $user->getAuthIdentifier(),
                reasonCode: $guarded['reason_code'],
                note: $guarded['note'] ?? null,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['order' => 'Not authorized to manage pharmacy orders.']);
        } catch (DomainException | InvalidArgumentException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('status', 'Order rejected with a coded reason.');
    }

    public function hold(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $pharmacyOrderId,
    ): RedirectResponse {
        $guarded = $this->guard($request, $context, $pharmacyOrderId, [
            'reason_code' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if (is_string($guarded)) {
            return back()->withErrors(['order' => $guarded]);
        }
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $commandBus->dispatch(new HoldPharmacyOrder(
                pharmacyOrderId: $pharmacyOrderId,
                actorId: (string) $user->getAuthIdentifier(),
                reasonCode: $guarded['reason_code'],
                note: $guarded['note'] ?? null,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['order' => 'Not authorized to manage pharmacy orders.']);
        } catch (DomainException | InvalidArgumentException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('status', 'Order held with a coded reason.');
    }

    public function releaseHold(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $pharmacyOrderId,
    ): RedirectResponse {
        $guarded = $this->guard($request, $context, $pharmacyOrderId, []);
        if (is_string($guarded)) {
            return back()->withErrors(['order' => $guarded]);
        }
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $commandBus->dispatch(new ReleasePharmacyOrderHold(
                pharmacyOrderId: $pharmacyOrderId,
                actorId: (string) $user->getAuthIdentifier(),
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['order' => 'Not authorized to manage pharmacy orders.']);
        } catch (DomainException | InvalidArgumentException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('status', 'Hold released — order work resumes.');
    }

    public function cancel(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $pharmacyOrderId,
    ): RedirectResponse {
        $guarded = $this->guard($request, $context, $pharmacyOrderId, [
            'reason_code' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if (is_string($guarded)) {
            return back()->withErrors(['order' => $guarded]);
        }
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            $commandBus->dispatch(new CancelPharmacyOrder(
                pharmacyOrderId: $pharmacyOrderId,
                actorId: (string) $user->getAuthIdentifier(),
                reasonCode: $guarded['reason_code'],
                note: $guarded['note'] ?? null,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['order' => 'Not authorized to manage pharmacy orders.']);
        } catch (DomainException | InvalidArgumentException $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('status', 'Order cancelled with a coded reason.');
    }
}
