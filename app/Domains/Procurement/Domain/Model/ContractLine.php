<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\ContractLineFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One agreed item/service on a purchase contract (19-procurement-domain.md §40,
 * §41; ADR-176 D-B).
 *
 * Plain Eloquent child — NOT an AggregateRoot (the `pcr_rfq_lines` /
 * `pcr_po_lines` precedent). Written directly by `CreateContractHandler`.
 * `item_code` / `item_name` are SNAPSHOTS from `InventoryApi::getItem` (STOCK
 * lines). `quantity_ceiling` NULL = no per-line cap. `version` is shape /
 * conformance-only — stays 0.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_id
 * @property int $line_no
 * @property string $line_type
 * @property string|null $item_ref
 * @property string|null $item_code
 * @property string|null $item_name
 * @property string|null $description
 * @property string $agreed_unit_price
 * @property string|null $quantity_ceiling
 * @property string $uom
 * @property int $version
 */
class ContractLine extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ContractLineFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    public const LINE_TYPE_STOCK = 'STOCK';

    public const LINE_TYPE_SERVICE = 'SERVICE';

    protected $table = 'pcr_contract_lines';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'contract_id', 'line_no', 'line_type',
        'item_ref', 'item_code', 'item_name', 'description',
        'agreed_unit_price', 'quantity_ceiling', 'uom',
        'version',
    ];

    protected static function newFactory(): ContractLineFactory
    {
        return ContractLineFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'agreed_unit_price' => 'decimal:2',
            'quantity_ceiling' => 'decimal:4',
            'version' => 'integer',
        ];
    }
}
