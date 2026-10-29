<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\ValueObject\PlanStatus;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Set a plan ACTIVE or INACTIVE (ADR-198 D-H). Per-subject `manage`. INACTIVE
 * means "not available for new member coverage"; historical coverage / claims
 * are never deleted (§319).
 */
final readonly class SetPayerPlanStatus implements Auditable, AuthorizesAgainst
{
    public string $status;

    public ?string $reason;

    public function __construct(
        public string $planId,
        public string $actorId,
        string $status,
        ?string $reason = null,
    ) {
        if (! Str::isUuid($planId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('SetPayerPlanStatus ids must be UUIDs (IC §3).');
        }

        $this->status = PlanStatus::assert($status);
        $reason = $reason !== null ? trim($reason) : null;
        $this->reason = $reason === '' ? null : $reason;
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->planId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.payer_plan_status_set',
            actorId: $this->actorId,
            targetType: 'payer_plan',
            targetId: $this->planId,
            reason: $this->reason,
            metadata: ['status' => $this->status],
        );
    }
}
