<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Domain\Model;

use App\Platform\Reporting\Domain\ValueObject\ReportCategory;
use App\Shared\Domain\HasUuid;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string $owning_domain
 * @property ReportCategory $category
 * @property string|null $description
 * @property string|null $calculation_logic
 * @property string|null $unit_of_measure
 * @property int $version
 * @property string $status
 * @property string|null $signed_off_by
 * @property CarbonImmutable|null $signed_off_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MetricDefinition extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rpt_metric_definitions';

    protected $fillable = [
        'id',
        'tenant_id',
        'code',
        'name',
        'owning_domain',
        'category',
        'description',
        'calculation_logic',
        'unit_of_measure',
        'version',
        'status',
        'signed_off_by',
        'signed_off_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => ReportCategory::class,
            'version' => 'integer',
            'signed_off_at' => 'immutable_datetime',
        ];
    }
}
