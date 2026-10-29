<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Enum\RelationshipRole;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record a person related to a patient — emergency contact / next of kin /
 * guardian / parent / … (10-patient-domain.md §35-§38, §130; ADR-126). The
 * §130 command names AddEmergencyContact / UpdateNextOfKin are folded into
 * this one role-parameterised command against the single
 * pat_patient_relationships aggregate (ADR-126 D-6).
 *
 * `$relationshipId` is client-minted (a UUIDv7) so this Auditable command names
 * its own target before the row exists. `$relatedName` / `$relatedPhone` are
 * PHI: they are never placed in the audit metadata or any event.
 */
final readonly class RecordPatientRelationship implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $relationshipId,
        public string $patientId,
        public string $actorId,
        public RelationshipRole $role,
        public string $relatedName,
        public ?string $relationshipLabel = null,
        public ?string $relatedPhone = null,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($relationshipId)) {
            throw new InvalidArgumentException('RecordPatientRelationship::$relationshipId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('RecordPatientRelationship::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RecordPatientRelationship::$actorId must be a UUID (IC §3).');
        }

        if (trim($relatedName) === '') {
            throw new InvalidArgumentException('RecordPatientRelationship::$relatedName must not be blank.');
        }
    }

    public function policyAbility(): string
    {
        return 'recordRelationship';
    }

    public function subjectId(): ?string
    {
        return null; // create — class-level check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'patient.relationship_recorded',
            actorId: $this->actorId,
            targetType: 'patient',
            targetId: $this->patientId,
            metadata: [
                'relationship_id' => $this->relationshipId,
                'role' => $this->role->value, // coded; name / phone are PHI and omitted
            ],
        );
    }
}
