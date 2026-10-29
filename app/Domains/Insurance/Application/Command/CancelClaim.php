<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cancels an unsubmitted claim and releases its claimed charges (ADR-204; Invariant 7).
 * Per-subject `cancel` (`insurance.claims.manage`).
 */
final readonly class CancelClaim implements Auditable, AuthorizesAgainst
{
    public string $reason;

    public function __construct(
        public string $claimId,
        public string $actorId,
        string $reason,
    ) {
        foreach (['claimId' => $claimId, 'actorId' => $actorId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CancelClaim::\${$field} must be a UUID (IC §3).");
            }
        }

        $this->reason = trim($reason);
        if ($this->reason === '') {
            throw new InvalidArgumentException('CancelClaim::$reason must not be empty.');
        }
    }

    public function policyAbility(): string
    {
        return 'cancel';
    }

    public function subjectId(): string
    {
        return $this->claimId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.claim_cancelled',
            actorId: $this->actorId,
            targetType: 'claim',
            targetId: $this->claimId,
            metadata: [
                'reason' => $this->reason,
            ],
        );
    }
}
