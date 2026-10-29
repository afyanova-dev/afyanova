<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Deactivate a patient relationship record (ADR-126 D-8 — deactivation, not
 * hard delete; the row and its audit trail are preserved). Audited (§103).
 */
final readonly class RemovePatientRelationship implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $relationshipId,
        public string $actorId,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($relationshipId)) {
            throw new InvalidArgumentException('RemovePatientRelationship::$relationshipId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RemovePatientRelationship::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'removeRelationship';
    }

    public function subjectId(): string
    {
        return $this->relationshipId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'patient.relationship_removed',
            actorId: $this->actorId,
            targetType: 'patient_relationship',
            targetId: $this->relationshipId,
            reason: $this->reason,
        );
    }
}
