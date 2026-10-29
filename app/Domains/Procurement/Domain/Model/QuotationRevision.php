<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\QuotationRevisionFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only pre-close revision of a quotation (19-procurement-domain.md
 * §233; ADR-173 D-C).
 *
 * Plain append-only child — NOT an AggregateRoot (the `Quotation` aggregate
 * bumps `revision_count`; this table records the before/after — the
 * `pcr_po_amendments` / `pcr_po_receipts` pattern). `Rls::enableAppendOnly` at
 * the database. `version` nominal — stays 0. `UPDATED_AT` disabled.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $quotation_id
 * @property int $sequence
 * @property string $field_changed
 * @property string|null $previous_value
 * @property string|null $new_value
 * @property string $reason
 * @property string $revised_by_user_id
 * @property CarbonImmutable $revised_at
 * @property int $version
 */
class QuotationRevision extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<QuotationRevisionFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    public const UPDATED_AT = null;

    protected $table = 'pcr_quotation_revisions';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'quotation_id', 'sequence', 'field_changed',
        'previous_value', 'new_value', 'reason',
        'revised_by_user_id', 'revised_at', 'version',
    ];

    protected static function newFactory(): QuotationRevisionFactory
    {
        return QuotationRevisionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'revised_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
