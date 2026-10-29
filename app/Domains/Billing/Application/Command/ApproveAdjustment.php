<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Approve a REQUESTED adjustment — REQUESTED → APPLIED (the signed ledger
 * `ADJUSTMENT` posts in the same step) (20-billing-and-payments-domain.md §165;
 * ADR-191 G-3). Per-subject `approve` (`billing.adjustments.approve` — a
 * DISTINCT key from `.request`). The approver may NOT be the requester
 * (`SelfApprovalNotPermitted`).
 */
final readonly class ApproveAdjustment implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $actorId,
        public string $adjustmentId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($adjustmentId)) {
            throw new InvalidArgumentException('ApproveAdjustment ids must be UUIDs (IC §3).');
        }
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
            action: 'billing.adjustment_approved',
            actorId: $this->actorId,
            targetType: 'adjustment',
            targetId: $this->adjustmentId,
        );
    }
}
