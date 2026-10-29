<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\CancelPurchaseOrder;
use App\Domains\Procurement\Presentation\Http\Request\CancelPurchaseOrderRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CancelPurchaseOrderController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(CancelPurchaseOrderRequest $request, string $purchaseOrderId): RedirectResponse
    {
        abort_unless(Str::isUuid($purchaseOrderId), 404);

        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        try {
            $this->commands->dispatch(new CancelPurchaseOrder(
                $purchaseOrderId,
                (string) $user->id,
                (string) $request->validated()['reason'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return back()->with('status', 'Purchase order cancelled.');
    }
}
