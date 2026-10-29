<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\RejectStockAdjustment;
use App\Domains\Inventory\Presentation\Http\Request\RejectStockAdjustmentRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Reject a pending stock adjustment with a required decision note — no stock
 * change (18-inventory-and-warehousing-domain.md §94, §95; ADR-170 D-H).
 */
final class RejectStockAdjustmentController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(RejectStockAdjustmentRequest $request, string $adjustmentId): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->commands->dispatch(new RejectStockAdjustment(
                adjustmentId: $adjustmentId,
                actorId: (string) $request->user()->id,
                reason: $data['reason'],
                idempotencyKey: $data['idempotency_key'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['adjustment' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('inventory.index', ['section' => 'adjustments'])
            ->with('status', 'Stock adjustment rejected.');
    }
}
