<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Runs deterministic readiness validation on a claim (§217/§218; ADR-204).
 * Per-subject `manage` (`insurance.claims.manage`).
 */
final readonly class ValidateClaimReadiness implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $claimId,
        public string $actorId,
    ) {
        foreach (['claimId' => $claimId, 'actorId' => $actorId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ValidateClaimReadiness::\${$field} must be a UUID (IC §3).");
            }
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->claimId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.claim_readiness_validated',
            actorId: $this->actorId,
            targetType: 'claim',
            targetId: $this->claimId,
        );
    }
}
