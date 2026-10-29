<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Domain\Model;

use App\Shared\Domain\HasUuid;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $aggregate_date
 * @property string $encounter_type
 * @property int $total_encounters
 * @property int $total_admissions
 * @property int $total_discharges
 * @property int $emergency_count
 * @property float $avg_duration_minutes
 * @property CarbonImmutable $as_of_time
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class EncounterDailyAggregate extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rpt_encounter_daily_aggregates';

    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'aggregate_date',
        'encounter_type',
        'total_encounters',
        'total_admissions',
        'total_discharges',
        'emergency_count',
        'avg_duration_minutes',
        'as_of_time',
    ];

    protected function casts(): array
    {
        return [
            'total_encounters' => 'integer',
            'total_admissions' => 'integer',
            'total_discharges' => 'integer',
            'emergency_count' => 'integer',
            'avg_duration_minutes' => 'float',
            'as_of_time' => 'immutable_datetime',
        ];
    }
}
