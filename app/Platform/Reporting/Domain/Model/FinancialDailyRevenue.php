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
 * @property string $revenue_date
 * @property string $payment_method
 * @property float $total_billed
 * @property float $total_collected
 * @property float $total_insurance_claimed
 * @property float $total_adjustments
 * @property CarbonImmutable $as_of_time
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class FinancialDailyRevenue extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rpt_financial_daily_revenue';

    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'revenue_date',
        'payment_method',
        'total_billed',
        'total_collected',
        'total_insurance_claimed',
        'total_adjustments',
        'as_of_time',
    ];

    protected function casts(): array
    {
        return [
            'total_billed' => 'float',
            'total_collected' => 'float',
            'total_insurance_claimed' => 'float',
            'total_adjustments' => 'float',
            'as_of_time' => 'immutable_datetime',
        ];
    }
}
