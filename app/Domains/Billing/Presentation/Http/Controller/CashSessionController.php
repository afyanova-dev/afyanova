<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\Controller;

use App\Domains\Billing\Application\Command\CloseCashSession;
use App\Domains\Billing\Application\Command\OpenCashSession;
use App\Domains\Billing\Presentation\Http\Request\CloseCashSessionRequest;
use App\Domains\Billing\Presentation\Http\Request\OpenCashSessionRequest;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

final class CashSessionController
{
    public function open(
        OpenCashSessionRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        try {
            $commandBus->dispatch(new OpenCashSession(
                actorId: (string) $user->getAuthIdentifier(),
                cashSessionId: (string) Str::uuid7(),
                facilityId: $facilityId,
                openingFloat: (string) $request->input('opening_float'),
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['cash_session' => 'Not authorized to open a cash session.']);
        } catch (DomainException $e) {
            return back()->withErrors(['cash_session' => $e->getMessage()]);
        }

        return redirect()->route('billing.index')->with('status', 'Cash session opened.');
    }

    public function close(
        CloseCashSessionRequest $request,
        TenantContext $context,
        CommandBus $commandBus,
        string $cashSessionId,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        if (! Str::isUuid($cashSessionId)) {
            return back()->withErrors(['cash_session' => 'Unknown cash session.']);
        }

        try {
            $commandBus->dispatch(new CloseCashSession(
                actorId: (string) $user->getAuthIdentifier(),
                cashSessionId: $cashSessionId,
                countedCash: (string) $request->input('counted_cash'),
                note: $request->filled('note') ? trim((string) $request->input('note')) : null,
            ));
        } catch (AuthorizationException) {
            return back()->withErrors(['cash_session' => 'Not authorized to close this cash session.']);
        } catch (DomainException $e) {
            return back()->withErrors(['cash_session' => $e->getMessage()]);
        }

        return redirect()->route('billing.index')->with('status', 'Cash session closed. Variance recorded on the session.');
    }
}
