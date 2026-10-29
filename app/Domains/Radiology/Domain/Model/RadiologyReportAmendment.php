<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Model;

use App\Domains\Radiology\Database\Factories\RadiologyReportAmendmentFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One append-only amendment row on a radiology report (15-radiology-domain.md
 * §44, §96, §164, §184; ADR-168 D-B).
 *
 * Plain append-only child — NOT an AggregateRoot: the `RadiologyReport`
 * aggregate emits `RadiologyReportAmended` / `RadiologyReportCancelled`
 * (the `lab_result_amendments` / `thr_operative_note_amendments` precedent).
 * `UPDATED_AT = null` (append-only, `Rls::enableAppendOnly`).
 *
 * `version` is shape/conformance-only (ConformanceCommand check 3b) — stays 0
 * for the row's life, NOT optimistic concurrency (the standing
 * `governance-rulings` interpretation).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $report_id
 * @property int $sequence
 * @property string $amendment_type
 * @property string $previous_status
 * @property string $new_status
 * @property string|null $previous_findings_text
 * @property string|null $previous_impression_text
 * @property string|null $previous_recommendations_text
 * @property string|null $new_findings_text
 * @property string|null $new_impression_text
 * @property string|null $new_recommendations_text
 * @property string $reason
 * @property string $amended_by_user_id
 * @property CarbonImmutable|null $amended_at
 * @property int $version
 */
class RadiologyReportAmendment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RadiologyReportAmendmentFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    public const UPDATED_AT = null;

    public const TYPE_CORRECTION = 'CORRECTION';

    public const TYPE_ADDENDUM = 'ADDENDUM';

    protected $table = 'rad_report_amendments';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'report_id',
        'sequence',
        'amendment_type',
        'previous_status',
        'new_status',
        'previous_findings_text',
        'previous_impression_text',
        'previous_recommendations_text',
        'new_findings_text',
        'new_impression_text',
        'new_recommendations_text',
        'reason',
        'amended_by_user_id',
        'amended_at',
        'version',
    ];

    protected static function newFactory(): RadiologyReportAmendmentFactory
    {
        return RadiologyReportAmendmentFactory::new();
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
