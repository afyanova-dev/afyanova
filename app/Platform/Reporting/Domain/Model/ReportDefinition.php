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
 * @property ReportCategory $category
 * @property string|null $description
 * @property string $data_mart_code
 * @property array<string, mixed> $query_template
 * @property string|null $schedule_cron
 * @property array<string>|null $distribution_list
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ReportDefinition extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rpt_report_definitions';

    protected $fillable = [
        'id',
        'tenant_id',
        'code',
        'name',
        'category',
        'description',
        'data_mart_code',
        'query_template',
        'schedule_cron',
        'distribution_list',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'category' => ReportCategory::class,
            'query_template' => 'array',
            'distribution_list' => 'array',
        ];
    }
}
