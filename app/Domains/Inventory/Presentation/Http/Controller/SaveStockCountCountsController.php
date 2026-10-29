<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\SaveStockCountCounts;
use App\Domains\Inventory\Presentation\Http\Request\SaveStockCountCountsRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Write down the quantities found on a count's sheet (Phase 8, stock counts).
 * The count only records what was found; it never edits a balance.
 */
final class SaveStockCountCountsController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(SaveStockCountCountsRequest $request, string $stockCountId): RedirectResponse
    {
        $data = $request->validated();

        /** @var array<string, string|int|float|null> $counts */
        $counts = is_array($data['counts'] ?? null) ? $data['counts'] : [];

        try {
            $this->commands->dispatch(new SaveStockCountCounts(
                stockCountId: $stockCountId,
                actorId: (string) $request->user()?->getKey(),
                counts: $counts,
                idempotencyKey: $data['idempotency_key'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['stock_count' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('inventory.index', ['section' => 'counts', 'count_id' => $stockCountId])
            ->with('status', 'Counts saved.');
    }
}
