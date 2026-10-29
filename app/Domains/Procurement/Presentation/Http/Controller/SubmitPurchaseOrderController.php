<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\SubmitPurchaseOrder;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * DRAFT → SUBMITTED. The bus checks `procurement.orders.create` at the order's
 * own facility (`PurchaseOrderPolicy::submit`); the free-text reason is not
 * part of this step (§44).
 */
final class SubmitPurchaseOrderController
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
            $this->commands->dispatch(new SubmitPurchaseOrder($purchaseOrderId, (string) $user->id));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return back()->with('status', 'Purchase order submitted for approval.');
    }
}
