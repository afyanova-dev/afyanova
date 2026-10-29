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
 * Reverse an APPLIED write-off — APPLIED → REVERSED, a compensating `WRITE_OFF`
 * / `DEBIT` restores the receivable (20-billing-and-payments-domain.md §72 /
 * §134; ADR-191 D7-B). Per-subject `approve` (`billing.writeoffs.approve` — the
 * same key posture as the forward approval). FULL reversal only. The reverser
 * may NOT be the original requester (`SelfApprovalNotPermitted`). Idempotent on
 * an already-REVERSED write-off.
 */
final readonly class ReverseWriteOff implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    public function __construct(
        public string $actorId,
        public string $writeOffId,
        string $reasonCode,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($writeOffId)) {
            throw new InvalidArgumentException('ReverseWriteOff ids must be UUIDs (IC §3).');
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
            action: 'billing.write_off_reversed',
            actorId: $this->actorId,
            targetType: 'write_off',
            targetId: $this->writeOffId,
            reason: $this->reasonCode,
        );
    }
}
