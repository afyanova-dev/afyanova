<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\CreateStockAdjustment;
use App\Domains\Inventory\Presentation\Http\Request\CreateStockAdjustmentRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Raise a stock adjustment (18-inventory-and-warehousing-domain.md §49, §94,
 * §95; ADR-170 D-H). The adjustment enters PENDING_APPROVAL; the redirect
 * returns to the facility worklist where it can be reviewed.
 */
final class CreateStockAdjustmentController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(CreateStockAdjustmentRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $actor = $request->user() ?? throw new AuthenticationException;

        try {
            $this->commands->dispatch(new CreateStockAdjustment(
                adjustmentId: $data['adjustment_id'],
                actorId: (string) $actor->getAuthIdentifier(),
                itemId: $data['item_id'],
                locationId: $data['location_id'],
                direction: $data['direction'],
                quantity: (string) $data['quantity'],
                reasonCode: $data['reason_code'],
                reasonText: $data['reason_text'] ?? null,
                witnessName: $data['witness_name'] ?? null,
                batchNumber: $data['batch_number'] ?? null,
                idempotencyKey: $data['idempotency_key'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['adjustment' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('inventory.index', ['section' => 'adjustments'])
            ->with('status', 'Stock adjustment requested for approval.');
    }
}
