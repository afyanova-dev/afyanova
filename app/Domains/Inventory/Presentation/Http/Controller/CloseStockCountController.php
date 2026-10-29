<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\CloseStockCount;
use App\Domains\Inventory\Application\Command\CloseStockCountResult;
use App\Domains\Inventory\Presentation\Http\Request\CloseStockCountRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Finish a stock count — keep the sheet (COMPLETE) or throw it away (DISCARD)
 * (Phase 8, stock counts). Completing raises one pending stock adjustment per
 * difference for a second person to approve; no balance is touched here.
 */
final class CloseStockCountController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(CloseStockCountRequest $request, string $stockCountId): RedirectResponse
    {
        $data = $request->validated();

        try {
            /** @var CloseStockCountResult $result */
            $result = $this->commands->dispatch(new CloseStockCount(
                stockCountId: $stockCountId,
                actorId: (string) $request->user()?->getKey(),
                decision: $data['decision'],
                witnessName: $data['witness_name'] ?? null,
                idempotencyKey: $data['idempotency_key'],
                counts: $data['counts'] ?? [],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['stock_count' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('inventory.index', ['section' => 'counts'])
            ->with('status', $this->message($result));
    }

    private function message(CloseStockCountResult $result): string
    {
        if (! $result->completed) {
            return 'Stock count discarded.';
        }

        $raised = $result->adjustmentsRaised;
        $skipped = $result->linesSkipped;

        if ($raised === 0 && $skipped === 0) {
            return 'Stock count completed. No differences were found.';
        }

        $parts = [];
        if ($raised > 0) {
            $parts[] = $raised.' '.($raised === 1 ? 'correction' : 'corrections').' sent for approval';
        }
        if ($skipped > 0) {
            $parts[] = $skipped.' '.($skipped === 1 ? 'difference was' : 'differences were')
                .' not corrected automatically';
        }

        return 'Stock count completed — '.implode('; ', $parts).'.';
    }
}
