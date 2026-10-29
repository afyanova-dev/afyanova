<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Controller;

use App\Domains\Billing\Application\Command\RetryBillableEvent;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class BillableEventController
{
    public function retry(
        Request $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $billableEventId,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->can('billing.charges.manage'), 403, 'Unauthorized.');

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        if (! Str::isUuid($billableEventId)) {
            return back()->withErrors(['catalog' => 'Unknown billable event.']);
        }

        try {
            $commandBus->dispatch(new RetryBillableEvent(
                actorId: (string) $user->getAuthIdentifier(),
                billableEventId: $billableEventId,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['catalog' => 'Not authorized to retry charges.']);
        } catch (DomainException $e) {
            return back()->withErrors(['catalog' => $e->getMessage()]);
        }

        return back()->with('status', 'Event re-attempted. A priced, mapped service now charges.');
    }
}
