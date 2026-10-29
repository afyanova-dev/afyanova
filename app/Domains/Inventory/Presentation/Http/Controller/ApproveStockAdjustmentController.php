<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\ApproveStockAdjustment;
use App\Domains\Inventory\Presentation\Http\Request\ApproveStockAdjustmentRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Approve a pending stock adjustment, posting the compensating movement
 * (18-inventory-and-warehousing-domain.md §94, §95, §224; ADR-170 D-H). The
 * handler owns the lifecycle and the no-self-approval rule.
 */
final class ApproveStockAdjustmentController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(ApproveStockAdjustmentRequest $request, string $adjustmentId): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->commands->dispatch(new ApproveStockAdjustment(
                adjustmentId: $adjustmentId,
                actorId: (string) $request->user()->id,
                idempotencyKey: $data['idempotency_key'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['adjustment' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('inventory.index', ['section' => 'adjustments'])
            ->with('status', 'Stock adjustment approved.');
    }
}
