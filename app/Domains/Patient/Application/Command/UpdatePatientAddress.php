<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Enum\AddressType;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create, revise or deactivate a patient address (10-patient-domain.md
 * §33-§34, §130; ADR-126). §130 lists a single address command and §132 a
 * single event — this is one upsert keyed on the client-minted `$addressId`:
 * absent → created, present → revised, `$isActive === false` → deactivated.
 *
 * A MOVE is recorded as a new address (new `$addressId`) with the previous one
 * deactivated — §34: the prior row is never erased. This command never touches
 * `pat_patients` (an address is not §26 demographics; it has no effect on
 * `is_provisional` — ADR-119). §33 field values are PHI: never in the audit
 * metadata or any event.
 */
final readonly class UpdatePatientAddress implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $addressId,
        public string $patientId,
        public string $actorId,
        public AddressType $type,
        public ?string $region = null,
        public ?string $district = null,
        public ?string $ward = null,
        public ?string $streetOrVillage = null,
        public ?string $postalCode = null,
        public ?string $notes = null,
        public bool $isActive = true,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($addressId)) {
            throw new InvalidArgumentException('UpdatePatientAddress::$addressId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('UpdatePatientAddress::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdatePatientAddress::$actorId must be a UUID (IC §3).');
        }

        $hasContent = array_filter(
            [$region, $district, $ward, $streetOrVillage, $postalCode, $notes],
            static fn (?string $part): bool => $part !== null && trim($part) !== '',
        );

        if ($hasContent === []) {
            throw new InvalidArgumentException('UpdatePatientAddress: at least one §33 address field must be provided.');
        }
    }

    public function policyAbility(): string
    {
        return 'updateAddress';
    }

    public function subjectId(): ?string
    {
        return null; // upsert — class-level check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'patient.address_updated',
            actorId: $this->actorId,
            targetType: 'patient',
            targetId: $this->patientId,
            metadata: [
                'address_id' => $this->addressId,
                'type' => $this->type->value, // coded; §33 field values are PHI and omitted
                'is_active' => $this->isActive,
            ],
        );
    }
}
