<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Approve a REQUESTED write-off — REQUESTED → APPLIED (the ledger `WRITE_OFF` /
 * `CREDIT` posts in the same step) (20-billing-and-payments-domain.md §165;
 * ADR-191 G-3). Per-subject `approve` (`billing.writeoffs.approve` — a DISTINCT
 * key from `.request`, §164 / §165). The approver may NOT be the requester
 * (`SelfApprovalNotPermitted`).
 */
final readonly class ApproveWriteOff implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $actorId,
        public string $writeOffId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($actorId) || ! Str::isUuid($writeOffId)) {
            throw new InvalidArgumentException('ApproveWriteOff ids must be UUIDs (IC §3).');
        }
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
            action: 'billing.write_off_approved',
            actorId: $this->actorId,
            targetType: 'write_off',
            targetId: $this->writeOffId,
        );
    }
}
