<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\RequisitionLineFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One requested item/service on a purchase requisition
 * (19-procurement-domain.md §18, §52, §53; ADR-171 D-E).
 *
 * Plain Eloquent child — NOT an AggregateRoot (the `lab_order_items` /
 * `rad_imaging_order_items` precedent). Written directly by
 * `CreatePurchaseRequisitionHandler`. `item_code` / `item_name` are SNAPSHOTS
 * from `InventoryApi::getItem` at create time (STOCK lines only). `version` is
 * shape / conformance-only — stays 0.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $requisition_id
 * @property int $line_no
 * @property string $line_type
 * @property string|null $item_ref
 * @property string|null $item_code
 * @property string|null $item_name
 * @property string|null $description
 * @property string $quantity the quantity the requester entered (in `$unit`)
 * @property string $uom the item's BASE unit (stock is counted in this)
 * @property string $unit the unit the requester entered (e.g. BOX)
 * @property string|null $pack_size base units per `$unit`; null when `$unit` IS the base unit
 * @property string $ordered_base_qty generated: quantity * coalesce(pack_size, 1)
 * @property CarbonImmutable|null $required_date
 * @property string $status
 * @property int $version
 */
class RequisitionLine extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RequisitionLineFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    public const LINE_TYPE_STOCK = 'STOCK';

    public const LINE_TYPE_SERVICE = 'SERVICE';

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'pcr_requisition_lines';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'requisition_id', 'line_no', 'line_type',
        'item_ref', 'item_code', 'item_name', 'description',
        'quantity', 'uom', 'unit', 'pack_size', 'required_date',
        'status', 'version',
    ];

    protected static function newFactory(): RequisitionLineFactory
    {
        return RequisitionLineFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity' => 'decimal:4',
            'pack_size' => 'decimal:4',
            'ordered_base_qty' => 'decimal:4',
            'required_date' => 'immutable_date',
            'version' => 'integer',
        ];
    }
}
