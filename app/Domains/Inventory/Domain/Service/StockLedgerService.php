<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Service;

use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockMovement;
use InvalidArgumentException;

/**
 * The single chokepoint every stock-quantity change funnels through
 * (18-inventory-and-warehousing-domain.md §48, §252, §407; ADR-170 D-I).
 *
 * Pure domain logic, no I/O. The handler has already: allocated the movement
 * number, built the immutable `StockMovement`, and loaded the `StockBalance`
 * `FOR UPDATE` (creating a zeroed row if absent — blocker B1). This service
 * dispatches to the matching `StockBalance` mutator, which adjusts `on_hand_qty`,
 * enforces the Inv 5 / §307 negative-stock guard (no §132 override in Inv.1),
 * and records the PHI-safe event. The handler then saves the movement + the
 * balance in the one command transaction.
 */
final class StockLedgerService
{
    /**
     * Post a RECEIPT or CONSUMPTION movement against a balance. `$releaseReserved`
     * (ADR-180 D-D; default '0') is the portion of a CONSUMPTION that fulfils an
     * existing reservation — subtracted from `reserved_qty` alongside `on_hand`.
     */
    public function post(StockBalance $balance, StockMovement $movement, string $releaseReserved = '0'): void
    {
        match ($movement->movement_type) {
            StockMovement::TYPE_RECEIPT => $balance->applyReceipt($movement),
            StockMovement::TYPE_CONSUMPTION => $balance->applyConsumption($movement, $releaseReserved),
            default => throw new InvalidArgumentException(
                "StockLedgerService::post does not handle movement_type [{$movement->movement_type}]."
            ),
        };
    }

    /**
     * Post the compensating IN movement for a consumption reversal (ADR-185 D-C)
     * — the "corrective inverse" category, distinct from an approved adjustment.
     */
    public function postConsumptionReversal(StockBalance $balance, StockMovement $movement, string $originalMovementId): void
    {
        if ($movement->movement_type !== StockMovement::TYPE_CONSUMPTION_REVERSAL) {
            throw new InvalidArgumentException(
                "StockLedgerService::postConsumptionReversal requires a CONSUMPTION_REVERSAL movement, got [{$movement->movement_type}]."
            );
        }

        $balance->applyConsumptionReversal($movement, $originalMovementId);
    }

    public function postSupplierReturn(StockBalance $balance, StockMovement $movement, string $returnId): void
    {
        if ($movement->movement_type !== StockMovement::TYPE_RETURN_OUT
            || $movement->direction !== StockMovement::DIRECTION_OUT) {
            throw new InvalidArgumentException(
                'StockLedgerService::postSupplierReturn requires a RETURN_OUT movement.'
            );
        }

        $balance->applySupplierReturn($movement, $returnId);
    }

    /**
     * Post one side of a stock transfer (Phase 8). A transfer writes two
     * movements in one transaction — an OUT at the source and an IN at the
     * destination — so the facility total never changes. Only the OUT side is
     * guarded against negative stock.
     */
    public function postTransfer(StockBalance $balance, StockMovement $movement, string $counterpartLocationId): void
    {
        if (! in_array($movement->movement_type, [StockMovement::TYPE_TRANSFER_OUT, StockMovement::TYPE_TRANSFER_IN], true)) {
            throw new InvalidArgumentException(
                "StockLedgerService::postTransfer requires a TRANSFER_OUT or TRANSFER_IN movement, got [{$movement->movement_type}]."
            );
        }

        $balance->applyTransfer($movement, $counterpartLocationId);
    }

    /** Post the compensating movement for an approved adjustment. */
    public function postAdjustment(StockBalance $balance, StockMovement $movement, StockAdjustment $adjustment): void
    {
        if ($movement->movement_type !== StockMovement::TYPE_ADJUSTMENT) {
            throw new InvalidArgumentException(
                "StockLedgerService::postAdjustment requires an ADJUSTMENT movement, got [{$movement->movement_type}]."
            );
        }

        $balance->applyAdjustment(
            $movement,
            (string) $adjustment->id,
            (string) $adjustment->reason_code,
            (string) $adjustment->approved_by_user_id,
        );
    }
}
