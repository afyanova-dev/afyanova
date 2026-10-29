<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Link an encounter to a care episode (26-care-coordination-domain.md §6, §9;
 * ADR-153 D-E). `$linkId` is client-minted (UUIDv7 — the CreateReferral
 * precedent). `facility_id` / `patient_id` are copied from the encounter
 * context by the handler, never from this command. `$role` / `$reason` are
 * ROW-ONLY (ADR-136 deny-list — never on `EncounterLinkedToEpisode`).
 */
final readonly class LinkEncounterToEpisode implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $linkId,
        public string $episodeId,
        public string $encounterId,
        public string $actorId,
        public ?string $role = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($linkId)) {
            throw new InvalidArgumentException('LinkEncounterToEpisode::$linkId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($episodeId)) {
            throw new InvalidArgumentException('LinkEncounterToEpisode::$episodeId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('LinkEncounterToEpisode::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('LinkEncounterToEpisode::$actorId must be a UUID (IC §3).');
        }

        if ($role !== null && trim($role) === '') {
            throw new InvalidArgumentException('LinkEncounterToEpisode::$role must be a non-blank string or null.');
        }
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.episode_encounter_linked',
            actorId: $this->actorId,
            targetType: 'care_episode_encounter',
            targetId: $this->linkId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
            metadata: [
                'care_episode_id' => $this->episodeId,
                'encounter_id' => $this->encounterId,
            ],
        );
    }
}
