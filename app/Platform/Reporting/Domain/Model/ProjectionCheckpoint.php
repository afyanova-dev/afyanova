<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Domain\Model;

use App\Platform\Reporting\Domain\ValueObject\ProjectionStreamStatus;
use App\Shared\Domain\HasUuid;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $stream_name
 * @property int $checkpoint_offset
 * @property CarbonImmutable|null $last_event_timestamp
 * @property ProjectionStreamStatus $status
 * @property string $reference_config_version
 * @property CarbonImmutable|null $last_rebuilt_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ProjectionCheckpoint extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rpt_projection_checkpoints';

    protected $fillable = [
        'id',
        'tenant_id',
        'stream_name',
        'checkpoint_offset',
        'last_event_timestamp',
        'status',
        'reference_config_version',
        'last_rebuilt_at',
    ];

    protected function casts(): array
    {
        return [
            'checkpoint_offset' => 'integer',
            'last_event_timestamp' => 'immutable_datetime',
            'status' => ProjectionStreamStatus::class,
            'last_rebuilt_at' => 'immutable_datetime',
        ];
    }
}
