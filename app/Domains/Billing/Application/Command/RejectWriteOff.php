<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\ValueObject\FinancialControlReversalReason;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Decline a REQUESTED write-off — REQUESTED → REJECTED, no ledger entry
 * (20-billing-and-payments-domain.md §165; ADR-191 G-3). Per-subject `approve`
 * (rejecting is an approver action). Terminal.
 */
final readonly class RejectWriteOff implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    public function __construct(
        public string $actorId,
        public string $writeOffId,
        string $reasonCode,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($writeOffId)) {
            throw new InvalidArgumentException('RejectWriteOff ids must be UUIDs (IC §3).');
        }

        $this->reasonCode = FinancialControlReversalReason::assert($reasonCode);
    }

    public function policyAbility(): string
    {
        return 'approve';
    }

    public function subjectId(): string
    {
        return $this->writeOffId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.write_off_rejected',
            actorId: $this->actorId,
            targetType: 'write_off',
            targetId: $this->writeOffId,
            reason: $this->reasonCode,
        );
    }
}
