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
 * @property string $dispense_date
 * @property int $total_prescriptions
 * @property int $total_active
 * @property int $total_cancelled
 * @property CarbonImmutable $as_of_time
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PharmacyDailyAggregate extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rpt_pharmacy_daily_aggregates';

    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'dispense_date',
        'total_prescriptions',
        'total_active',
        'total_cancelled',
        'as_of_time',
    ];

    protected function casts(): array
    {
        return [
            'total_prescriptions' => 'integer',
            'total_active' => 'integer',
            'total_cancelled' => 'integer',
            'as_of_time' => 'immutable_datetime',
        ];
    }
}
