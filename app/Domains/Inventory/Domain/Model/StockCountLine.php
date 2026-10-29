<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Domains\Inventory\Domain\Quantity;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a stock count (frontend-foundation-inventory-operations.md Phase 8,
 * stock counts).
 *
 * Plain Eloquent row — NOT an AggregateRoot; the `StockCount` document owns the
 * lifecycle. `system_qty` is the SNAPSHOT of the on-hand quantity when the count
 * started; `counted_qty` is what the counter found, and stays NULL until they
 * write it down (NULL means "not counted", never zero). The variance is the
 * difference and is computed, never stored.
 *
 * Quantities are the item's BASE unit, like every other stock figure.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $stock_count_id
 * @property string $item_id
 * @property string|null $batch_id
 * @property string $system_qty
 * @property string|null $counted_qty
 * @property string|null $note
 * @property string|null $counted_by_user_id
 * @property CarbonImmutable|null $counted_at
 * @property string|null $variance_adjustment_id
 * @property string|null $variance_skipped_reason
 */
final class StockCountLine extends Model
{
    use BelongsToTenant;

    /** The count was completed before the stock could change — raise it by hand. */
    public const SKIPPED_STOCK_MOVED = 'STOCK_MOVED';

    /** The completing actor may not raise stock adjustments. */
    public const SKIPPED_NEEDS_PERMISSION = 'NEEDS_ADJUSTMENT_PERMISSION';

    protected $table = 'inv_stock_count_lines';

    /** UUID primary key — without this Eloquent casts `id` to an integer. */
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'stock_count_id',
        'item_id', 'batch_id',
        'system_qty', 'counted_qty',
        'note', 'counted_by_user_id', 'counted_at',
        'variance_adjustment_id', 'variance_skipped_reason',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'system_qty' => 'decimal:4',
            'counted_qty' => 'decimal:4',
            'counted_at' => 'immutable_datetime',
        ];
    }

    /**
     * counted − system, or NULL when the line has not been counted. A positive
     * value is stock found beyond the record (an increase to raise); a negative
     * value is stock missing (a decrease, which needs a second approver).
     */
    public function variance(): ?string
    {
        if ($this->counted_qty === null) {
            return null;
        }

        return Quantity::sub((string) $this->counted_qty, (string) $this->system_qty);
    }

    /**
     * True only when the line was counted AND the count disagrees with the
     * snapshot. An uncounted line is not a variance (it is not a zero), and a
     * counted line that matches the record needs no correction.
     */
    public function hasVariance(): bool
    {
        $variance = $this->variance();

        return $variance !== null && Quantity::compare($variance, '0') !== 0;
    }

    /**
     * The size of the difference, always positive. The direction is decided by
     * the sign of `variance()`: negative means stock is missing.
     */
    public function varianceQuantity(): ?string
    {
        $variance = $this->variance();

        if ($variance === null || Quantity::compare($variance, '0') === 0) {
            return null;
        }

        return Quantity::isNegative($variance) ? Quantity::sub('0', $variance) : Quantity::of($variance);
    }
}
