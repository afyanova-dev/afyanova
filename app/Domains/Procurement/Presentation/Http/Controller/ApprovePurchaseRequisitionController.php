<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\ApprovePurchaseRequisition;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * SUBMITTED → APPROVED. Besides `procurement.requisitions.approve` at the request's facility,
 * the handler refuses the person who raised it — no money limit relaxes this step (§24 / §228).
 */
final class ApprovePurchaseRequisitionController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(Request $request, string $requisitionId): RedirectResponse
    {
        abort_unless(Str::isUuid($requisitionId), 404);

        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        try {
            $this->commands->dispatch(new ApprovePurchaseRequisition($requisitionId, (string) $user->id));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['request' => $exception->getMessage()]);
        }

        return back()->with('status', 'Request approved. You can turn it into a purchase order.');
    }
}
