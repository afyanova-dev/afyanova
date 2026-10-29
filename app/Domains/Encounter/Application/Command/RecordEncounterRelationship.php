<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record a relationship from this encounter to another (11-encounter-domain.md
 * §172; ADR-151 D-C). `relationshipType` is open-coded — §172 names
 * FOLLOW_UP_OF / READMISSION_OF / TRANSFERRED_FROM / REFERRED_FROM /
 * RELATED_TO but nothing hardcodes that set.
 *
 * The ONLY intended caller in CC.1 is Care Coordination's own
 * `LinkReferralEncounterHandler`, via `EncounterApi::recordEncounterRelationship`
 * — never a direct cross-module dispatch of this command (IC §2.1).
 */
final readonly class RecordEncounterRelationship implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $relationshipId,
        public string $encounterId,
        public string $relatedEncounterId,
        public string $relationshipType,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($relationshipId)) {
            throw new InvalidArgumentException('RecordEncounterRelationship::$relationshipId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('RecordEncounterRelationship::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($relatedEncounterId)) {
            throw new InvalidArgumentException('RecordEncounterRelationship::$relatedEncounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RecordEncounterRelationship::$actorId must be a UUID (IC §3).');
        }

        if (trim($relationshipType) === '') {
            throw new InvalidArgumentException('RecordEncounterRelationship::$relationshipType must not be blank.');
        }
    }

    public function policyAbility(): string
    {
        return 'recordRelationship';
    }

    public function subjectId(): string
    {
        return $this->encounterId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'encounter.relationship_recorded',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
            metadata: [
                'related_encounter_id' => $this->relatedEncounterId,
                'relationship_type' => $this->relationshipType,
            ],
        );
    }
}
