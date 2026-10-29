<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\TransferStock;
use App\Domains\Inventory\Presentation\Http\Request\TransferStockRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

final class TransferStockController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(TransferStockRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->commands->dispatch(new TransferStock(
                transferId: $data['transfer_id'],
                actorId: (string) $request->user()?->id,
                itemId: $data['item_id'],
                fromLocationId: $data['from_location_id'],
                toLocationId: $data['to_location_id'],
                quantity: (string) $data['quantity'],
                batchNumber: $data['batch_number'] ?? null,
                occurredAt: $data['occurred_at'] ?? null,
                reason: $data['reason'] ?? null,
                idempotencyKey: $data['idempotency_key'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['transfer' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('inventory.index', [
            'section' => 'transfers',
            'transfer_from' => $data['from_location_id'],
        ])->with('status', 'Stock moved.');
    }
}
