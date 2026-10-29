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
 * @property string|null $ward_id
 * @property string $occupancy_date
 * @property int $total_beds
 * @property int $occupied_beds
 * @property float $occupancy_rate_percent
 * @property int $admissions_count
 * @property int $discharges_count
 * @property CarbonImmutable $as_of_time
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class BedOccupancyDaily extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rpt_bed_occupancy_daily';

    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'ward_id',
        'occupancy_date',
        'total_beds',
        'occupied_beds',
        'occupancy_rate_percent',
        'admissions_count',
        'discharges_count',
        'as_of_time',
    ];

    protected function casts(): array
    {
        return [
            'total_beds' => 'integer',
            'occupied_beds' => 'integer',
            'occupancy_rate_percent' => 'float',
            'admissions_count' => 'integer',
            'discharges_count' => 'integer',
            'as_of_time' => 'immutable_datetime',
        ];
    }
}
