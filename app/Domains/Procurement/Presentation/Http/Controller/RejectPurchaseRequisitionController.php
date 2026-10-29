<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\RejectPurchaseRequisition;
use App\Domains\Procurement\Presentation\Http\Request\RejectPurchaseRequisitionRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * SUBMITTED → REJECTED. Needs `procurement.requisitions.approve` at the
 * request's facility, a reason, and someone other than the requester (§24).
 */
final class RejectPurchaseRequisitionController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(RejectPurchaseRequisitionRequest $request, string $requisitionId): RedirectResponse
    {
        abort_unless(Str::isUuid($requisitionId), 404);

        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        try {
            $this->commands->dispatch(new RejectPurchaseRequisition(
                $requisitionId,
                (string) $user->id,
                (string) $request->validated()['reason'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['request' => $exception->getMessage()]);
        }

        return back()->with('status', 'Request rejected.');
    }
}
