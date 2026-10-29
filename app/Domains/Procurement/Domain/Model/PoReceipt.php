<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\PoReceiptFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only Purchase-Order receipt record
 * (19-procurement-domain.md §56, §125–§129; ADR-172 D-E).
 *
 * Plain append-only child — NOT an AggregateRoot (the `PurchaseOrder` aggregate
 * rolls its own status; this table records the physical-receipt fact Procurement
 * matched to it — the `pcr_po_amendments` / `inv_stock_movements` pattern).
 * Written directly by `RecordPurchaseOrderReceiptHandler`. `movement_*` /
 * `item_ref` / `batch_ref` / `location_ref` are OPAQUE Inventory identifiers.
 * `version` stays 0. `UPDATED_AT` disabled — rows are never modified.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $po_id
 * @property string|null $po_line_id
 * @property string|null $goods_receipt_ref
 * @property string|null $goods_receipt_line_ref
 * @property string $source_event_id
 * @property string $movement_id
 * @property string $movement_number
 * @property string $item_ref
 * @property string|null $batch_ref
 * @property string $location_ref
 * @property string $quantity
 * @property string $uom
 * @property string $source_type
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
class PoReceipt extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PoReceiptFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    public const UPDATED_AT = null;

    protected $table = 'pcr_po_receipts';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'po_id', 'po_line_id',
        'goods_receipt_ref', 'goods_receipt_line_ref',
        'source_event_id', 'movement_id', 'movement_number',
        'item_ref', 'batch_ref', 'location_ref',
        'quantity', 'uom', 'source_type',
        'received_at', 'recorded_at', 'version',
    ];

    protected static function newFactory(): PoReceiptFactory
    {
        return PoReceiptFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'received_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
