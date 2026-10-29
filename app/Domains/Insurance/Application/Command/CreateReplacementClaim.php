<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates an atomic replacement claim superseding a SUBMITTED claim (ADR-204; Invariant 7).
 * Per-subject `supersede` on the original claim (`insurance.claims.manage`).
 */
final readonly class CreateReplacementClaim implements Auditable, AuthorizesAgainst
{
    public ?string $primaryDiagnosisCode;

    public ?string $notes;

    public function __construct(
        public string $replacementClaimId,
        public string $actorId,
        public string $supersedesClaimId,
        ?string $primaryDiagnosisCode = null,
        public ?string $authorizationId = null,
        ?string $notes = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['replacementClaimId' => $replacementClaimId, 'actorId' => $actorId, 'supersedesClaimId' => $supersedesClaimId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreateReplacementClaim::\${$field} must be a UUID (IC §3).");
            }
        }

        if ($authorizationId !== null && ! Str::isUuid($authorizationId)) {
            throw new InvalidArgumentException('CreateReplacementClaim::$authorizationId must be a UUID or null (IC §3).');
        }

        $this->primaryDiagnosisCode = self::clean($primaryDiagnosisCode);
        $this->notes = self::clean($notes);
    }

    public function policyAbility(): string
    {
        return 'supersede';
    }

    public function subjectId(): string
    {
        return $this->supersedesClaimId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.claim_replacement_created',
            actorId: $this->actorId,
            targetType: 'claim',
            targetId: $this->replacementClaimId,
            metadata: [
                'supersedes_claim_id' => $this->supersedesClaimId,
                'authorization_id' => $this->authorizationId,
                'primary_diagnosis_code' => $this->primaryDiagnosisCode,
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
