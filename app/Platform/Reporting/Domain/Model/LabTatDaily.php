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
 * @property string $tat_date
 * @property string $test_category
 * @property int $total_tests_completed
 * @property float $avg_tat_minutes
 * @property int $within_target_count
 * @property int $breached_target_count
 * @property CarbonImmutable $as_of_time
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class LabTatDaily extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rpt_lab_tat_daily';

    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'tat_date',
        'test_category',
        'total_tests_completed',
        'avg_tat_minutes',
        'within_target_count',
        'breached_target_count',
        'as_of_time',
    ];

    protected function casts(): array
    {
        return [
            'total_tests_completed' => 'integer',
            'avg_tat_minutes' => 'float',
            'within_target_count' => 'integer',
            'breached_target_count' => 'integer',
            'as_of_time' => 'immutable_datetime',
        ];
    }
}
