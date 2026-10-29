<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\StartStockCount;
use App\Domains\Inventory\Presentation\Http\Request\StartStockCountRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Start a physical stock count at one location (Phase 8, stock counts). The
 * handler snapshots what the system believes is there into the sheet.
 */
final class StartStockCountController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(StartStockCountRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $this->commands->dispatch(new StartStockCount(
                stockCountId: $data['stock_count_id'],
                actorId: (string) $request->user()?->getKey(),
                locationId: $data['location_id'],
                notes: $data['notes'] ?? null,
                idempotencyKey: $data['idempotency_key'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['stock_count' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('inventory.index', ['section' => 'counts', 'count_id' => $data['stock_count_id']])
            ->with('status', 'Stock count started.');
    }
}
