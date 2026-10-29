<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\PoAmendmentFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only purchase-order amendment record
 * (19-procurement-domain.md §67, §68, §231, §232; ADR-171 D-I).
 *
 * Plain append-only child — NOT an AggregateRoot (the `PurchaseOrder` aggregate
 * emits `PurchaseOrderAmended`; this table records the before/after — the
 * `lab_result_amendments` / `rad_report_amendments` pattern). `Rls::enableAppendOnly`
 * at the database. `version` stays 0 (the standing `governance-rulings`
 * interpretation). `UPDATED_AT` disabled — rows are never modified.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $po_id
 * @property int $sequence
 * @property string $amendment_type
 * @property string|null $po_line_id
 * @property string $field_changed
 * @property string|null $previous_value
 * @property string|null $new_value
 * @property string $reason
 * @property string $amended_by_user_id
 * @property CarbonImmutable $amended_at
 * @property int $version
 */
class PoAmendment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<PoAmendmentFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    public const TYPE_QUANTITY = 'QUANTITY';

    public const TYPE_PRICE = 'PRICE';

    public const TYPE_DELIVERY_DATE = 'DELIVERY_DATE';

    public const TYPE_DELIVERY_LOCATION = 'DELIVERY_LOCATION';

    public const TYPE_NOTES = 'NOTES';

    public const TYPE_OTHER = 'OTHER';

    public const UPDATED_AT = null;

    protected $table = 'pcr_po_amendments';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'po_id', 'sequence', 'amendment_type', 'po_line_id',
        'field_changed', 'previous_value', 'new_value', 'reason',
        'amended_by_user_id', 'amended_at', 'version',
    ];

    protected static function newFactory(): PoAmendmentFactory
    {
        return PoAmendmentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amended_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
