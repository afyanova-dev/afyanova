<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Enum\ContactType;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create, revise or deactivate a SECONDARY patient contact point
 * (10-patient-domain.md §31-§32, §130; ADR-126). §130 lists a single contact
 * command and §132 a single event — so this is one upsert keyed on the
 * client-minted `$contactId`: absent → created, present → revised,
 * `$isActive === false` → deactivated.
 *
 * This never touches `pat_patients.primary_phone` / `email` (ADR-126 D-2 —
 * those are core Patient fields for the later UpdatePatient capability).
 * `$value` / `$label` are PHI: never placed in the audit metadata or any event.
 */
final readonly class UpdatePatientContact implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $contactId,
        public string $patientId,
        public string $actorId,
        public ContactType $type,
        public string $value,
        public ?string $label = null,
        public bool $isPreferred = false,
        public bool $isActive = true,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($contactId)) {
            throw new InvalidArgumentException('UpdatePatientContact::$contactId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('UpdatePatientContact::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdatePatientContact::$actorId must be a UUID (IC §3).');
        }

        if (trim($value) === '') {
            throw new InvalidArgumentException('UpdatePatientContact::$value must not be blank.');
        }
    }

    public function policyAbility(): string
    {
        return 'updateContact';
    }

    public function subjectId(): ?string
    {
        return null; // upsert — class-level check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'patient.contact_updated',
            actorId: $this->actorId,
            targetType: 'patient',
            targetId: $this->patientId,
            metadata: [
                'contact_id' => $this->contactId,
                'type' => $this->type->value, // coded; value / label are PHI and omitted
                'is_active' => $this->isActive,
            ],
        );
    }
}
