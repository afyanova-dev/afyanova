<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Domain\Model;

use App\Platform\Reporting\Domain\ValueObject\ReportRunStatus;
use App\Shared\Domain\HasUuid;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $run_number
 * @property string|null $report_definition_id
 * @property string $report_definition_code
 * @property array<string, mixed>|null $parameters
 * @property ReportRunStatus $status
 * @property string|null $requested_by
 * @property string|null $facility_id
 * @property CarbonImmutable $as_of_time
 * @property int $data_freshness_lag_seconds
 * @property array<string, mixed>|null $result_dataset
 * @property int $row_count
 * @property string|null $document_id
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ReportRun extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rpt_report_runs';

    protected $fillable = [
        'id',
        'tenant_id',
        'run_number',
        'report_definition_id',
        'report_definition_code',
        'parameters',
        'status',
        'requested_by',
        'facility_id',
        'as_of_time',
        'data_freshness_lag_seconds',
        'result_dataset',
        'row_count',
        'document_id',
        'failure_reason',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReportRunStatus::class,
            'parameters' => 'array',
            'result_dataset' => 'array',
            'row_count' => 'integer',
            'data_freshness_lag_seconds' => 'integer',
            'as_of_time' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
