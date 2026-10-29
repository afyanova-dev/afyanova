<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Detach an encounter from a care episode (26-care-coordination-domain.md §8,
 * §9; ADR-153 D-E). The link row is kept (status DETACHED), never
 * hard-deleted (§256). `$reason` is REQUIRED (§256 "detach is audited") and
 * is audit-only (ADR-136 deny-list — never on `EncounterDetachedFromEpisode`).
 */
final readonly class DetachEncounterFromEpisode implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $linkId,
        public string $actorId,
        public string $reason,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($linkId)) {
            throw new InvalidArgumentException('DetachEncounterFromEpisode::$linkId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('DetachEncounterFromEpisode::$actorId must be a UUID (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('DetachEncounterFromEpisode::$reason must not be blank (§256).');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->linkId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.episode_encounter_detached',
            actorId: $this->actorId,
            targetType: 'care_episode_encounter',
            targetId: $this->linkId,
            reason: trim($this->reason),
        );
    }
}
