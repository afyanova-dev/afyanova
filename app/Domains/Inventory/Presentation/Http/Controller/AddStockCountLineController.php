<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\AddStockCountLine;
use App\Domains\Inventory\Presentation\Http\Request\AddStockCountLineRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Put an item that the system does not list onto a count's sheet — a physical
 * find (Phase 8, stock counts).
 */
final class AddStockCountLineController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(AddStockCountLineRequest $request, string $stockCountId): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->commands->dispatch(new AddStockCountLine(
                stockCountId: $stockCountId,
                actorId: (string) $request->user()?->getKey(),
                itemId: $data['item_id'],
                batchNumber: $data['batch_number'] ?? null,
                idempotencyKey: $data['idempotency_key'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['stock_count' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('inventory.index', ['section' => 'counts', 'count_id' => $stockCountId])
            ->with('status', 'Item added to the count.');
    }
}
