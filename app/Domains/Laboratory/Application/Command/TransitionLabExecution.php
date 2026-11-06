<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Domains\Laboratory\Domain\Model\LabExecution;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class TransitionLabExecution implements Auditable, AuthorizesAgainst
{
    public const TARGET_COMPLETED = LabExecution::STATUS_COMPLETED;

    public const TARGET_ABORTED = LabExecution::STATUS_ABORTED;

    public function __construct(
        public string $executionId,
        public string $actorId,
        public string $targetStatus,
        public ?string $reason = null,
    ) {
        foreach (['executionId' => $executionId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("TransitionLabExecution::\${$name} must be a UUID.");
            }
        }

        if (! in_array($targetStatus, [self::TARGET_COMPLETED, self::TARGET_ABORTED], true)) {
            throw new InvalidArgumentException('TransitionLabExecution::$targetStatus is invalid.');
        }

        if ($targetStatus === self::TARGET_ABORTED && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('A reason is required to abort a laboratory execution.');
        }
    }

    public function policyAbility(): string
    {
        return 'execute';
    }

    public function subjectId(): ?string
    {
        return $this->executionId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: $this->targetStatus === self::TARGET_COMPLETED
                ? 'laboratory.execution_completed'
                : 'laboratory.execution_aborted',
            actorId: $this->actorId,
            targetType: 'lab_execution',
            targetId: $this->executionId,
            metadata: ['status' => $this->targetStatus],
            reason: $this->reason,
        );
    }
}
