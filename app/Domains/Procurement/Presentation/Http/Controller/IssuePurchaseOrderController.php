<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\IssuePurchaseOrder;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * APPROVED → ISSUED. Besides `procurement.orders.issue` at the order's facility,
 * the handler refuses the approver issuing their own approval unless the
 * effective money limit covers it (§24 / §228; Shape B).
 */
final class IssuePurchaseOrderController
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
            $this->commands->dispatch(new IssuePurchaseOrder($purchaseOrderId, (string) $user->id));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return back()->with('status', 'Purchase order issued to the supplier. Receive the goods in Inventory.');
    }
}
