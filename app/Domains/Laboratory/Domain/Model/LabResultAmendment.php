<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Domains\Laboratory\Database\Factories\LabResultAmendmentFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An amendment appended to a laboratory result (14-laboratory-domain.md
 * §61–§64, §137; ADR-160 D-D). The `thr_operative_note_amendments` /
 * `thr_anaesthesia_record_amendments` precedent EXACTLY.
 *
 * Plain, append-only Eloquent child model — NOT an AggregateRoot (the
 * `LabResult` aggregate emits `LabResultAmended` / `LabResultCancelled`).
 * Never updated or deleted (`UPDATED_AT = null`; `Rls::enableAppendOnly` blocks
 * UPDATE/DELETE). `version` is SHAPE / CONFORMANCE-ONLY (the standing
 * `governance-rulings` interpretation — stays 0). `previous_value_text` /
 * `new_value_text` / `reason` are ROW-ONLY (ADR-136 deny-list).
 *
 * @property int $sequence
 * @property CarbonImmutable|null $amended_at
 * @property CarbonImmutable|null $created_at
 * @property int $version
 */
class LabResultAmendment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<LabResultAmendmentFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    public const TYPE_CORRECTION = 'CORRECTION';

    public const TYPE_CANCELLATION = 'CANCELLATION';

    protected $table = 'lab_result_amendments';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'result_id',
        'sequence',
        'amendment_type',
        'previous_status',
        'new_status',
        'previous_value_type',
        'previous_value_numeric',
        'previous_value_code',
        'previous_value_text',
        'new_value_type',
        'new_value_numeric',
        'new_value_code',
        'new_value_text',
        'reason',
        'replacement_result_id',
        'amended_by_user_id',
        'amended_at',
        'version',
    ];

    protected static function newFactory(): LabResultAmendmentFactory
    {
        return LabResultAmendmentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'amended_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
