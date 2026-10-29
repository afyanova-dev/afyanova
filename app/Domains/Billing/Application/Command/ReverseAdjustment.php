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
 * Reverse an APPLIED adjustment — APPLIED → REVERSED, a compensating
 * OPPOSITE-direction `ADJUSTMENT` ledger entry (20-billing-and-payments-domain.md
 * §72 / §134; ADR-191 D7-B). Per-subject `approve`. FULL reversal only. The
 * reverser may NOT be the original requester (`SelfApprovalNotPermitted`).
 * Idempotent on an already-REVERSED adjustment.
 */
final readonly class ReverseAdjustment implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    public function __construct(
        public string $actorId,
        public string $adjustmentId,
        string $reasonCode,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($adjustmentId)) {
            throw new InvalidArgumentException('ReverseAdjustment ids must be UUIDs (IC §3).');
        }

        $this->reasonCode = FinancialControlReversalReason::assert($reasonCode);
    }

    public function policyAbility(): string
    {
        return 'approve';
    }

    public function subjectId(): string
    {
        return $this->adjustmentId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'billing.adjustment_reversed',
            actorId: $this->actorId,
            targetType: 'adjustment',
            targetId: $this->adjustmentId,
            reason: $this->reasonCode,
        );
    }
}
