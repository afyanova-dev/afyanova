<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Close a care episode (26-care-coordination-domain.md §8, §9; ADR-153 D-E).
 * Closing does NOT close member encounters or detach links (Invariant 7.8).
 * `$reason` is audit-only (ADR-136 deny-list), never on `CareEpisodeClosed`.
 */
final readonly class CloseCareEpisode implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $episodeId,
        public string $actorId,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($episodeId)) {
            throw new InvalidArgumentException('CloseCareEpisode::$episodeId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CloseCareEpisode::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->episodeId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.episode_closed',
            actorId: $this->actorId,
            targetType: 'care_episode',
            targetId: $this->episodeId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
