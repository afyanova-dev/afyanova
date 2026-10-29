<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\ContractAmendmentFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only contract-amendment record (19-procurement-domain.md §195,
 * §248; ADR-178 D-B).
 *
 * Plain append-only child — NOT an AggregateRoot (the `Contract` aggregate bumps
 * `amendment_count` / `version`; this table records the before/after — the
 * `pcr_po_amendments` / `rad_report_amendments` pattern). `Rls::enableAppendOnly`
 * at the database. `version` stays 0. `UPDATED_AT` disabled — rows are never
 * modified. Tenant-wide (the `Contract` model — no `facility_id`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contract_id
 * @property int $sequence
 * @property string $amendment_type
 * @property string|null $contract_line_id
 * @property string $field_changed
 * @property string|null $previous_value
 * @property string|null $new_value
 * @property string $reason
 * @property string $amended_by_user_id
 * @property CarbonImmutable $amended_at
 * @property int $version
 */
class ContractAmendment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ContractAmendmentFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    public const TYPE_CEILING = 'CEILING';

    public const TYPE_EXPIRY = 'EXPIRY';

    public const TYPE_TERMS = 'TERMS';

    public const TYPE_TITLE = 'TITLE';

    public const TYPE_LINE_ADDED = 'LINE_ADDED';

    public const UPDATED_AT = null;

    protected $table = 'pcr_contract_amendments';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'contract_id', 'sequence', 'amendment_type', 'contract_line_id',
        'field_changed', 'previous_value', 'new_value', 'reason',
        'amended_by_user_id', 'amended_at', 'version',
    ];

    protected static function newFactory(): ContractAmendmentFactory
    {
        return ContractAmendmentFactory::new();
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
