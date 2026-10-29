<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record a payer's denial for an authorization (21-insurance-and-payer-domain.md
 * §58 / §63; ADR-202 D-B). Per-subject `approve` (`insurance.authorizations.approve`).
 */
final readonly class DenyAuthorization implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    public ?string $note;

    public function __construct(
        public string $authorizationId,
        public string $actorId,
        string $reasonCode,
        ?string $note = null,
    ) {
        foreach (['authorizationId' => $authorizationId, 'actorId' => $actorId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("DenyAuthorization::\${$field} must be a UUID (IC §3).");
            }
        }

        $this->reasonCode = trim($reasonCode);
        if ($this->reasonCode === '') {
            throw new InvalidArgumentException('DenyAuthorization::$reasonCode cannot be empty.');
        }

        $this->note = $note !== null && trim($note) !== '' ? trim($note) : null;
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
            action: 'insurance.authorization_denied',
            actorId: $this->actorId,
            targetType: 'authorization',
            targetId: $this->authorizationId,
            metadata: [
                'reason_code' => $this->reasonCode,
            ],
        );
    }
}
