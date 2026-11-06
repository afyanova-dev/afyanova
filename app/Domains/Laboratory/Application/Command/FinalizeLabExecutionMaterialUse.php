<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class FinalizeLabExecutionMaterialUse implements Auditable, AuthorizesAgainst
{
    public const OUTCOME_CONSUMED = 'CONSUMED';

    public const OUTCOME_FAILED = 'FAILED';

    public function __construct(
        public string $actorId,
        public string $executionId,
        public string $useId,
        public string $outcome,
        public ?string $failureMessage = null,
    ) {
        foreach (['actorId' => $actorId, 'executionId' => $executionId, 'useId' => $useId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("FinalizeLabExecutionMaterialUse::\${$name} must be a UUID.");
            }
        }

        if (! in_array($outcome, [self::OUTCOME_CONSUMED, self::OUTCOME_FAILED], true)) {
            throw new InvalidArgumentException('FinalizeLabExecutionMaterialUse::$outcome is invalid.');
        }

        if ($outcome === self::OUTCOME_FAILED && ($failureMessage === null || trim($failureMessage) === '')) {
            throw new InvalidArgumentException('A failure message is required for a failed material-use attempt.');
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
            action: $this->outcome === self::OUTCOME_CONSUMED
                ? 'laboratory.material_use_consumed'
                : 'laboratory.material_use_failed',
            actorId: $this->actorId,
            targetType: 'lab_execution_material_use',
            targetId: $this->useId,
            metadata: ['execution_id' => $this->executionId],
            reason: $this->failureMessage,
        );
    }
}
