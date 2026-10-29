<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Updates permitted draft metadata on a claim (ADR-204).
 * Per-subject `update` (`insurance.claims.manage`).
 */
final readonly class UpdateClaimDraft implements Auditable, AuthorizesAgainst
{
    public ?string $primaryDiagnosisCode;

    public ?string $notes;

    public function __construct(
        public string $claimId,
        public string $actorId,
        ?string $primaryDiagnosisCode = null,
        public ?string $authorizationId = null,
        ?string $notes = null,
    ) {
        foreach (['claimId' => $claimId, 'actorId' => $actorId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("UpdateClaimDraft::\${$field} must be a UUID (IC §3).");
            }
        }

        if ($authorizationId !== null && ! Str::isUuid($authorizationId)) {
            throw new InvalidArgumentException('UpdateClaimDraft::$authorizationId must be a UUID or null (IC §3).');
        }

        $this->primaryDiagnosisCode = self::clean($primaryDiagnosisCode);
        $this->notes = self::clean($notes);
    }

    public function policyAbility(): string
    {
        return 'update';
    }

    public function subjectId(): string
    {
        return $this->claimId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.claim_draft_updated',
            actorId: $this->actorId,
            targetType: 'claim',
            targetId: $this->claimId,
            metadata: [
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
