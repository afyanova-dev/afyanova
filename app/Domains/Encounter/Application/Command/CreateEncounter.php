<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Check in a patient — the authoritative creation of one attendance
 * (11-encounter-domain.md §8, §101; ADR-101, ADR-104, ADR-119, ADR-120,
 * ADR-121, ADR-130).
 *
 * `$encounterId` is client-minted (a UUIDv7) so this Auditable command names
 * its own target before the row exists (the RegisterPatient / AddPatientIdentifier
 * pattern) and repeat delivery is idempotent (§105). `$actorId` is the
 * authenticated principal (set at the HTTP boundary in PHASE 16 — routes are
 * deferred). `$tenantId` / `$facilityId` are NOT on the command — they are
 * resolved from TenantContext only (ADR-037, Invariant 12).
 *
 * `$planned = true` produces a PLANNED encounter (ADR-121); the default is a
 * walk-in ARRIVED check-in. `$departmentId` / `$unitId` are optional coarse
 * care-context location (ADR-130 B). `$source` defaults to
 * {@see DEFAULT_SOURCE} and is a free string in PHASE 5 (ADR-130 E).
 */
final readonly class CreateEncounter implements Auditable, AuthorizesAgainst
{
    /** ADR-130 E — the walk-in check-in default when no source is supplied. */
    public const DEFAULT_SOURCE = 'RECEPTION';

    public function __construct(
        public string $encounterId,
        public string $patientId,
        public string $actorId,
        public EncounterType $encounterType,
        public bool $planned = false,
        public ?string $departmentId = null,
        public ?string $unitId = null,
        public ?string $source = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('CreateEncounter::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('CreateEncounter::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CreateEncounter::$actorId must be a UUID (IC §3).');
        }

        if ($departmentId !== null && ! Str::isUuid($departmentId)) {
            throw new InvalidArgumentException('CreateEncounter::$departmentId must be a UUID or null (IC §3).');
        }

        if ($unitId !== null && ! Str::isUuid($unitId)) {
            throw new InvalidArgumentException('CreateEncounter::$unitId must be a UUID or null (IC §3).');
        }

        if ($source !== null && trim($source) === '') {
            throw new InvalidArgumentException('CreateEncounter::$source must be a non-blank string or null.');
        }
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null; // create — class-level check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'encounter.created',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
            metadata: [
                'patient_id' => $this->patientId,
                'encounter_type' => $this->encounterType->value, // coded
                'source' => $this->source ?? self::DEFAULT_SOURCE,
                'planned' => $this->planned,
            ],
        );
    }
}
