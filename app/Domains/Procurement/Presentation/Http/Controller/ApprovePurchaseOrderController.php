<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\ApprovePurchaseOrder;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * SUBMITTED → APPROVED. Besides `procurement.orders.approve` at the order's
 * facility, the handler refuses a creator approving their own order unless the
 * effective money limit covers it (§24 / §228; Shape B).
 */
final class ApprovePurchaseOrderController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(Request $request, string $purchaseOrderId): RedirectResponse
    {
        abort_unless(Str::isUuid($purchaseOrderId), 404);

        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        try {
            $this->commands->dispatch(new ApprovePurchaseOrder($purchaseOrderId, (string) $user->id));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return back()->with('status', 'Purchase order approved.');
    }
}
