<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Model;

use App\Platform\Integration\Domain\Enum\MappingSource;
use App\Platform\Integration\Domain\Enum\VerificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $connector_id
 * @property string $external_id
 * @property string $internal_domain
 * @property string $internal_id
 * @property MappingSource $mapping_source
 * @property VerificationStatus $verification_status
 * @property float $confidence
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 */
final class IdMapping extends Model
{
    use HasUuids;

    protected $table = 'int_id_mappings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mapping_source' => MappingSource::class,
            'verification_status' => VerificationStatus::class,
            'confidence' => 'float',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<IntegrationConnector, $this>
     */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnector::class, 'connector_id');
    }

    public function touchLastSeen(): void
    {
        $this->last_seen_at = CarbonImmutable::now();
    }
}
