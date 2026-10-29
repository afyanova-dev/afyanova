<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\RfqAwardFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One award allocation on an RFQ (19-procurement-domain.md §37, §38, §39;
 * ADR-173 D-E).
 *
 * Plain append-only child of `Rfq` — NOT an AggregateRoot (the `pcr_po_amendments`
 * / `pcr_po_receipts` pattern). Written by `AwardRfqHandler`. Split award = ≥ 2
 * rows for one `rfq_line_id` with different `quotation_id`; partial award = Σ
 * `awarded_qty` for a line < the RFQ line quantity. `Rls::enableAppendOnly` at
 * the database. `version` nominal — stays 0. `UPDATED_AT` disabled.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $rfq_id
 * @property string $rfq_line_id
 * @property string $quotation_id
 * @property string $quotation_line_id
 * @property string $supplier_id
 * @property string $awarded_qty
 * @property string $unit_price
 * @property string $reason
 * @property string $decided_by_user_id
 * @property CarbonImmutable $decided_at
 * @property int $version
 */
class RfqAward extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RfqAwardFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    public const UPDATED_AT = null;

    protected $table = 'pcr_rfq_awards';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'rfq_id', 'rfq_line_id', 'quotation_id', 'quotation_line_id', 'supplier_id',
        'awarded_qty', 'unit_price', 'reason',
        'decided_by_user_id', 'decided_at', 'version',
    ];

    protected static function newFactory(): RfqAwardFactory
    {
        return RfqAwardFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'awarded_qty' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'decided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
