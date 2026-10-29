<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Service;

use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\StockBalanceReader;
use App\Domains\Inventory\Domain\StockLedgerReader;

/**
 * Reconciles a materialised balance against Σ its movements
 * (18-inventory-and-warehousing-domain.md §85, §86, §253, §314, §408.4;
 * ADR-170 D-J). Compute-only — no reconciliation-record table, no resolution
 * workflow (§319 / §320 deferred to Inv.8+). Exercised by every stock-movement
 * feature test as the "balance remains reconcilable against the ledger"
 * invariant proof.
 */
final class StockReconciliationService
{
    public function __construct(
        private readonly StockLedgerReader $ledger,
        private readonly StockBalanceReader $balances,
    ) {}

    public function reconcile(string $itemId, string $locationId, ?string $batchId = null): ReconciliationResult
    {
        $ledgerSum = $this->ledger->signedMovementSum($itemId, $locationId, $batchId);
        $balanceOnHand = $this->balances->onHandTotal($itemId, $locationId, $batchId);

        return new ReconciliationResult(
            itemId: $itemId,
            locationId: $locationId,
            batchId: $batchId,
            balanceOnHand: $balanceOnHand,
            ledgerSum: $ledgerSum,
            drift: Quantity::sub($balanceOnHand, $ledgerSum),
        );
    }
}
