<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Domain\Model;

use App\Platform\Reporting\Domain\Exception\IllegalReturnTransition;
use App\Platform\Reporting\Domain\ValueObject\StatutoryFramework;
use App\Platform\Reporting\Domain\ValueObject\StatutoryReturnStatus;
use App\Shared\Domain\HasUuid;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $return_number
 * @property StatutoryFramework $framework
 * @property string $period_type
 * @property string $period_key
 * @property string|null $facility_id
 * @property string $reporting_unit_code
 * @property StatutoryReturnStatus $status
 * @property string|null $supersedes_return_id
 * @property string|null $superseded_by_return_id
 * @property array<string, mixed>|null $submitted_dataset
 * @property array<string, mixed>|null $submission_receipt
 * @property string|null $generated_by
 * @property string|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $acknowledged_at
 * @property string $reference_config_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class StatutoryReturn extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rpt_statutory_returns';

    protected $fillable = [
        'id',
        'tenant_id',
        'return_number',
        'framework',
        'period_type',
        'period_key',
        'facility_id',
        'reporting_unit_code',
        'status',
        'supersedes_return_id',
        'superseded_by_return_id',
        'submitted_dataset',
        'submission_receipt',
        'generated_by',
        'reviewed_by',
        'reviewed_at',
        'submitted_by',
        'submitted_at',
        'acknowledged_at',
        'reference_config_version',
    ];

    protected function casts(): array
    {
        return [
            'framework' => StatutoryFramework::class,
            'status' => StatutoryReturnStatus::class,
            'submitted_dataset' => 'array',
            'submission_receipt' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'acknowledged_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<StatutoryReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StatutoryReturnLine::class, 'return_id');
    }

    public function transitionTo(StatutoryReturnStatus $target): void
    {
        if (! $this->status->canTransitionTo($target)) {
            throw new IllegalReturnTransition("Cannot transition statutory return from {$this->status->value} to {$target->value}");
        }

        $this->status = $target;
    }
}
