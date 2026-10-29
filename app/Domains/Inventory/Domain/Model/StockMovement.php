<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain\Model;

use App\Domains\Inventory\Database\Factories\StockMovementFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One immutable Stock Movement ledger record
 * (18-inventory-and-warehousing-domain.md §47–§51, §77–§83, §84, §255;
 * ADR-170 D-G).
 *
 * A STANDALONE append-only ledger record — NOT an AggregateRoot, NOT a child of
 * any aggregate (the `clin_procedure_materials` / `lab_order_items` /
 * `rad_imaging_order_items` precedent). Written directly by the movement
 * handlers; corrections use a compensating movement, never an UPDATE (Inv 15).
 * `Rls::enableAppendOnly` at the database.
 *
 * `version` is shape / conformance-only (ConformanceCommand check 3b) — it
 * stays 0 for the row's life and is NOT optimistic concurrency (the standing
 * `governance-rulings` interpretation).
 *
 * NO `patient_id` / `encounter_id` — a §281 / §282 / §283 deviation from
 * ADR-132 (the causing activity is the opaque `source_ref`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $movement_number
 * @property string $movement_type
 * @property string $direction
 * @property string $quantity
 * @property string $uom
 * @property string|null $entered_unit how it was typed in (the buying unit), null for a plain base-unit receipt
 * @property string|null $entered_quantity how many of `entered_unit` arrived
 * @property string|null $pack_size base units in one `entered_unit` on THAT receipt (suppliers differ)
 * @property string $item_id
 * @property string $location_id
 * @property string|null $batch_id
 * @property string $source_type
 * @property string|null $source_ref
 * @property string|null $po_ref
 * @property string|null $po_line_ref the exact Procurement PO line received against (opaque)
 * @property string|null $goods_receipt_ref the GRN that posted this movement (opaque)
 * @property string|null $reason_text
 * @property string|null $reverses_movement_id the original CONSUMPTION OUT this movement reverses (ADR-185 D-C)
 * @property string $actor_user_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
class StockMovement extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    public const TYPE_RECEIPT = 'RECEIPT';

    public const TYPE_CONSUMPTION = 'CONSUMPTION';

    public const TYPE_ADJUSTMENT = 'ADJUSTMENT';

    /** The compensating IN posted by `ReverseConsumption` — the "corrective inverse" (ADR-185 D-C). */
    public const TYPE_CONSUMPTION_REVERSAL = 'CONSUMPTION_REVERSAL';

    /** The OUT side of a location-to-location transfer (Phase 8). */
    public const TYPE_TRANSFER_OUT = 'TRANSFER_OUT';

    /** The IN side of a location-to-location transfer (Phase 8). */
    public const TYPE_TRANSFER_IN = 'TRANSFER_IN';

    public const TYPE_RETURN_OUT = 'RETURN_OUT';

    public const DIRECTION_IN = 'IN';

    public const DIRECTION_OUT = 'OUT';

    protected $table = 'inv_stock_movements';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'movement_number', 'movement_type', 'direction', 'quantity', 'uom',
        'entered_unit', 'entered_quantity', 'pack_size',
        'item_id', 'location_id', 'batch_id',
        'source_type', 'source_ref', 'po_ref', 'po_line_ref', 'goods_receipt_ref', 'reason_text', 'reverses_movement_id',
        'actor_user_id', 'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): StockMovementFactory
    {
        return StockMovementFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'entered_quantity' => 'decimal:4',
            'pack_size' => 'decimal:4',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /** The signed balance delta this movement applies to on-hand stock (§84). */
    public function signedQuantity(): string
    {
        $qty = (string) $this->quantity;

        return $this->direction === self::DIRECTION_IN ? $qty : '-'.$qty;
    }
}
