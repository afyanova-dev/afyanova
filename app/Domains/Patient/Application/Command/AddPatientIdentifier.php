<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Enum\IdentifierType;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record an external / official identifier against a patient identity
 * (10-patient-domain.md §39, §130; ADR-117). Always stored UNVERIFIED —
 * verification is the separate VerifyPatientIdentifier command (§42). A
 * sensitive administrative operation — audited (§103).
 *
 * `$identifierId` is client-minted (a UUIDv7) so this Auditable command names
 * its own target before the row exists — the same pattern as RegisterPatient.
 * `$value` is PHI: it is never placed in the audit metadata or any event.
 */
final readonly class AddPatientIdentifier implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $identifierId,
        public string $patientId,
        public string $actorId,
        public IdentifierType $type,
        public string $value,
        public ?string $issuingAuthority = null,
        public ?string $effectiveFrom = null,
        public ?string $effectiveTo = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($identifierId)) {
            throw new InvalidArgumentException('AddPatientIdentifier::$identifierId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('AddPatientIdentifier::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AddPatientIdentifier::$actorId must be a UUID (IC §3).');
        }

        if (trim($value) === '') {
            throw new InvalidArgumentException('AddPatientIdentifier::$value must not be blank.');
        }
    }

    public function policyAbility(): string
    {
        return 'add';
    }

    public function subjectId(): ?string
    {
        return null; // create — class-level check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'patient.identifier_added',
            actorId: $this->actorId,
            targetType: 'patient',
            targetId: $this->patientId,
            metadata: [
                'identifier_id' => $this->identifierId,
                'identifier_type' => $this->type->value, // coded; the value itself is PHI and omitted
            ],
        );
    }
}
