<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One stock transfer between two locations of the same facility
 * (frontend-foundation-inventory-operations.md Phase 8).
 *
 * The transfer row is the document; the movement rows ARE the ledger. A
 * transfer posts a `TRANSFER_OUT` movement at `from_location_id` and a
 * `TRANSFER_IN` movement at `to_location_id` in the same transaction, so the
 * facility total never changes and no approval is needed. Each movement's
 * `source_ref` is this row's id.
 *
 * Emits NO events itself — the balances emit `StockTransferred` when each side
 * is applied. Aggregate-0 shape, like `StockAdjustment`.
 *
 * `reason_text` is free text, ROW-ONLY (the ADR-136 deny-list).
 *
 * @domain      Inventory
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $transfer_number
 * @property string $item_id
 * @property string $from_location_id
 * @property string $to_location_id
 * @property string|null $batch_id
 * @property string $quantity
 * @property string|null $reason_text
 * @property string $out_movement_id
 * @property string $in_movement_id
 * @property string $moved_by_user_id
 * @property CarbonImmutable $moved_at
 * @property int $version
 */
final class StockTransfer extends AggregateRoot
{
    protected $table = 'inv_stock_transfers';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'transfer_number',
        'item_id', 'from_location_id', 'to_location_id', 'batch_id',
        'quantity', 'reason_text',
        'out_movement_id', 'in_movement_id',
        'moved_by_user_id', 'moved_at',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'moved_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $transferNumber,
        string $itemId,
        string $fromLocationId,
        string $toLocationId,
        ?string $batchId,
        string $quantity,
        ?string $reasonText,
        string $outMovementId,
        string $inMovementId,
        string $movedByUserId,
        CarbonImmutable $movedAt,
    ): self {
        if ($fromLocationId === $toLocationId) {
            throw new InvalidArgumentException('StockTransfer::$toLocationId must differ from the source location.');
        }

        if (! Quantity::isPositive($quantity)) {
            throw new InvalidArgumentException('StockTransfer::$quantity must be positive.');
        }

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'transfer_number' => $transferNumber,
            'item_id' => $itemId,
            'from_location_id' => $fromLocationId,
            'to_location_id' => $toLocationId,
            'batch_id' => $batchId,
            'quantity' => $quantity,
            'reason_text' => $reasonText !== null && trim($reasonText) !== '' ? trim($reasonText) : null,
            'out_movement_id' => $outMovementId,
            'in_movement_id' => $inMovementId,
            'moved_by_user_id' => $movedByUserId,
            'moved_at' => $movedAt,
        ]);
    }
}
