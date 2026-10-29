<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Model;

use App\Platform\Queueing\Domain\Enum\QueueOperatingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Logical Queue definition.
 *
 * @authority coordination (PLATFORM SERVICE — does not own business truth)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $code
 * @property string $name
 * @property string|null $department_id
 * @property string|null $service_capability
 * @property string $ticket_prefix
 * @property bool $is_active
 * @property QueueOperatingStatus $operating_status
 * @property string|null $pause_reason
 * @property CarbonImmutable|null $paused_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Queue extends Model
{
    use HasUuids;

    protected $table = 'qms_queues';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'department_id',
        'service_capability',
        'ticket_prefix',
        'is_active',
        'operating_status',
        'pause_reason',
        'paused_at',
        'closed_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
        'operating_status' => QueueOperatingStatus::class,
        'paused_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @return HasMany<QueueEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(QueueEntry::class, 'queue_id');
    }

    public function pause(string $reason): void
    {
        $this->operating_status = QueueOperatingStatus::PAUSED;
        $this->pause_reason = $reason;
        $this->paused_at = CarbonImmutable::now();
    }

    public function resume(): void
    {
        $this->operating_status = QueueOperatingStatus::ACTIVE;
        $this->pause_reason = null;
        $this->paused_at = null;
    }

    public function close(?string $reason = null): void
    {
        $this->operating_status = QueueOperatingStatus::CLOSED;
        $this->pause_reason = $reason;
        $this->closed_at = CarbonImmutable::now();
    }

    public function isOperational(): bool
    {
        return $this->is_active && $this->operating_status === QueueOperatingStatus::ACTIVE;
    }
}
