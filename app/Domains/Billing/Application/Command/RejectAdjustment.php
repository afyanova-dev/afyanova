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
 * Decline a REQUESTED adjustment — REQUESTED → REJECTED, no ledger entry
 * (20-billing-and-payments-domain.md §165; ADR-191 G-3). Per-subject `approve`.
 */
final readonly class RejectAdjustment implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    public function __construct(
        public string $actorId,
        public string $adjustmentId,
        string $reasonCode,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($adjustmentId)) {
            throw new InvalidArgumentException('RejectAdjustment ids must be UUIDs (IC §3).');
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
            action: 'billing.adjustment_rejected',
            actorId: $this->actorId,
            targetType: 'adjustment',
            targetId: $this->adjustmentId,
            reason: $this->reasonCode,
        );
    }
}
