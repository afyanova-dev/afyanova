<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * End a member coverage (ADR-199 D-B). Per-subject `manage`. ACTIVE →
 * TERMINATED; idempotent when already TERMINATED. The row is never deleted
 * (§319). Terminating frees the `(patient, priority)` ACTIVE slot for a
 * replacement.
 */
final readonly class TerminateMemberCoverage implements Auditable, AuthorizesAgainst
{
    public ?string $reason;

    public function __construct(
        public string $memberCoverageId,
        public string $actorId,
        ?string $reason = null,
    ) {
        if (! Str::isUuid($memberCoverageId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('TerminateMemberCoverage ids must be UUIDs (IC §3).');
        }

        $reason = $reason !== null ? trim($reason) : null;
        $this->reason = $reason === '' ? null : $reason;
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->memberCoverageId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.member_coverage_terminated',
            actorId: $this->actorId,
            targetType: 'member_coverage',
            targetId: $this->memberCoverageId,
            reason: $this->reason,
        );
    }
}
