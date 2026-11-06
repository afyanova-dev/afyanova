<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Domains\Laboratory\Domain\Exception\IllegalLabExecutionTransition;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class LabExecutionMaterialUse extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use HasVersionColumn;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_CONSUMED = 'CONSUMED';

    public const STATUS_FAILED = 'FAILED';

    protected $table = 'lab_execution_material_uses';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'execution_id',
        'requirement_id',
        'attempt_number',
        'movement_id',
        'inventory_item_id',
        'location_id',
        'batch_number',
        'quantity',
        'status',
        'failure_message',
        'recorded_by_user_id',
        'consumed_at',
        'failed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'quantity' => 'decimal:4',
            'consumed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function markConsumed(CarbonImmutable $consumedAt): void
    {
        if ($this->status === self::STATUS_CONSUMED) {
            return;
        }

        if ($this->status !== self::STATUS_PENDING) {
            throw IllegalLabExecutionTransition::fromMaterialUseStatus((string) $this->status);
        }

        $this->status = self::STATUS_CONSUMED;
        $this->consumed_at = $consumedAt;
        $this->failure_message = null;
        $this->failed_at = null;
    }

    public function markFailed(string $message, CarbonImmutable $failedAt): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw IllegalLabExecutionTransition::fromMaterialUseStatus((string) $this->status);
        }

        $this->status = self::STATUS_FAILED;
        $this->failure_message = trim($message);
        $this->failed_at = $failedAt;
    }
}
