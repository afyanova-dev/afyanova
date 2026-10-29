<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Formally submits a claim to a third-party payer (ADR-204; Invariant 8).
 * Per-subject `submit` (`insurance.claims.submit` — Sponsor Ruling D3; separation of duties).
 */
final readonly class SubmitClaim implements Auditable, AuthorizesAgainst
{
    public ?string $externalReference;

    public function __construct(
        public string $claimId,
        public string $actorId,
        ?string $externalReference = null,
    ) {
        foreach (['claimId' => $claimId, 'actorId' => $actorId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("SubmitClaim::\${$field} must be a UUID (IC §3).");
            }
        }

        $this->externalReference = self::clean($externalReference);
    }

    public function policyAbility(): string
    {
        return 'submit';
    }

    public function subjectId(): string
    {
        return $this->claimId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.claim_submitted',
            actorId: $this->actorId,
            targetType: 'claim',
            targetId: $this->claimId,
            metadata: [
                'external_reference' => $this->externalReference,
            ],
        );
    }

    private static function clean(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim($v);

        return $t === '' ? null : $t;
    }
}
