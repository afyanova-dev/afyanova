<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Domains\Laboratory\Domain\Exception\IllegalLabExecutionTransition;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class LabExecution extends AggregateRoot
{
    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_ABORTED = 'ABORTED';

    protected $table = 'lab_executions';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'order_id',
        'order_item_id',
        'specimen_id',
        'attempt_number',
        'test_definition_id',
        'test_code_snapshot',
        'test_name_snapshot',
        'test_definition_version',
        'method_id',
        'method_code_snapshot',
        'method_name_snapshot',
        'method_version',
        'instrument_id',
        'instrument_identifier_snapshot',
        'instrument_version',
        'status',
        'started_by_user_id',
        'started_at',
        'completed_at',
        'aborted_at',
        'abort_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'test_definition_version' => 'integer',
            'method_version' => 'integer',
            'instrument_version' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'aborted_at' => 'immutable_datetime',
        ];
    }

    public function complete(CarbonImmutable $completedAt): void
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) {
            throw IllegalLabExecutionTransition::fromStatus((string) $this->status);
        }

        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = $completedAt;
    }

    public function abort(string $reason, CarbonImmutable $abortedAt): void
    {
        if ($this->status !== self::STATUS_IN_PROGRESS) {
            throw IllegalLabExecutionTransition::fromStatus((string) $this->status);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to abort a laboratory execution.');
        }

        $this->status = self::STATUS_ABORTED;
        $this->abort_reason = $reason;
        $this->aborted_at = $abortedAt;
    }
}
