<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\RfqLineFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One requested item/service on an RFQ (19-procurement-domain.md §30, §52;
 * ADR-173 D-B).
 *
 * Plain Eloquent child — NOT an AggregateRoot (the `pcr_requisition_lines` /
 * `pcr_po_lines` precedent). Written directly by `CreateRfqHandler`. `item_code`
 * / `item_name` are SNAPSHOTS from `InventoryApi::getItem` at create time
 * (STOCK lines). `version` is shape / conformance-only — stays 0.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $rfq_id
 * @property int $line_no
 * @property string $line_type
 * @property string|null $item_ref
 * @property string|null $item_code
 * @property string|null $item_name
 * @property string|null $description
 * @property string $quantity
 * @property string $uom
 * @property CarbonImmutable|null $required_date
 * @property int $version
 */
class RfqLine extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RfqLineFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    public const LINE_TYPE_STOCK = 'STOCK';

    public const LINE_TYPE_SERVICE = 'SERVICE';

    protected $table = 'pcr_rfq_lines';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'rfq_id', 'line_no', 'line_type',
        'item_ref', 'item_code', 'item_name', 'description',
        'quantity', 'uom', 'required_date',
        'version',
    ];

    protected static function newFactory(): RfqLineFactory
    {
        return RfqLineFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity' => 'decimal:4',
            'required_date' => 'immutable_date',
            'version' => 'integer',
        ];
    }
}
