<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cancel an authorization (21-insurance-and-payer-domain.md §64; ADR-202 D-B).
 * Per-subject `approve` (`insurance.authorizations.approve`).
 *
 * Allowed from REQUESTED, APPROVED, or PARTIALLY_APPROVED (e.g. payer revoked).
 */
final readonly class CancelAuthorization implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    public function __construct(
        public string $authorizationId,
        public string $actorId,
        string $reasonCode,
    ) {
        foreach (['authorizationId' => $authorizationId, 'actorId' => $actorId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CancelAuthorization::\${$field} must be a UUID (IC §3).");
            }
        }

        $this->reasonCode = trim($reasonCode);
        if ($this->reasonCode === '') {
            throw new InvalidArgumentException('CancelAuthorization::$reasonCode cannot be empty.');
        }
    }

    public function policyAbility(): string
    {
        return 'approve';
    }

    public function subjectId(): string
    {
        return $this->authorizationId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.authorization_cancelled',
            actorId: $this->actorId,
            targetType: 'authorization',
            targetId: $this->authorizationId,
            metadata: [
                'reason_code' => $this->reasonCode,
            ],
        );
    }
}
