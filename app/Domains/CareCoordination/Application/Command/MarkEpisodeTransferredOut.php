<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mark a care episode transferred out — its longitudinal responsibility moves
 * to another facility / provider (26-care-coordination-domain.md §8, §9;
 * ADR-153 D-E). A distinct terminal state from CLOSED. `$reason` is
 * audit-only (ADR-136 deny-list), never on `CareEpisodeTransferredOut`.
 */
final readonly class MarkEpisodeTransferredOut implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $episodeId,
        public string $actorId,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($episodeId)) {
            throw new InvalidArgumentException('MarkEpisodeTransferredOut::$episodeId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('MarkEpisodeTransferredOut::$actorId must be a UUID (IC §3).');
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
            action: 'care_coordination.episode_transferred_out',
            actorId: $this->actorId,
            targetType: 'care_episode',
            targetId: $this->episodeId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
